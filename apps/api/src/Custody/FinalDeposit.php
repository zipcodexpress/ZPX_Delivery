<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;
use Zpx\Shipping\Service as Shipping;

/** Reserves a physical destination door; custody remains with the driver until evidence is confirmed. */
final class FinalDeposit
{
    private Identity $identity;
    public function __construct(private PDO $db, private Secrets $crypto) { $this->identity = new Identity($db,$crypto); }
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }

    public function retry(string $user,string $session,array $input,string $key): array {
        Input::fields($input,['box_model_id']);Shipping::id($input['box_model_id']);
        $r=$this->q("SELECT *,encode(credential_hash,'hex') AS credential_hex FROM locker_sessions WHERE id=? AND actor_user_id=? AND status='CANCELLED' AND action='FINAL_DEPOSIT'",[$session,$user])->fetch(PDO::FETCH_ASSOC);
        if(!$r) throw new Failure(409,'DEPOSIT_RETRY_UNAVAILABLE','Only a declined deposit can select another size.');
        $ctx=json_decode($r['workflow_context'],true,512,JSON_THROW_ON_ERROR);
        return $this->prepareVerified($user,$ctx['run_id'],$ctx['stop_id'],['package_id'=>(string)$r['package_id'],'pairing_id'=>(string)$r['pairing_id'],'label_payload'=>'retry:'.$session,'expected_package_version'=>(int)$r['expected_package_version'],'expected_revision'=>$ctx['expected_revision'],'box_model_id'=>$input['box_model_id']],$key,'',$r['credential_hex']);
    }
    public function prepare(string $user,string $runId,string $stopId,array $input,string $key,string $match=''): array { return $this->prepareVerified($user,$runId,$stopId,$input,$key,$match); }
    private function prepareVerified(string $user,string $runId,string $stopId,array $input,string $key,string $match='',?string $verifiedHash=null): array
    {
        $this->identity->requireRole($user,'DRIVER');
        Shipping::id($runId); Shipping::id($stopId);
        Input::fields($input,['package_id','pairing_id','label_payload','expected_package_version','expected_revision'],['box_model_id']);
        $package=Shipping::id($input['package_id']); $pairing=Shipping::id($input['pairing_id']);
        $label=Input::text($input['label_payload'],1,500); Input::text($key,16,100);
        if (!is_int($input['expected_revision']) || $input['expected_revision']<1 || !is_int($input['expected_package_version']) || $input['expected_package_version']<0) {
            throw new Failure(422,'INVALID_INPUT','Expected revisions must be valid integers.');
        }
        $revision=$input['expected_revision'];
        if ($match!=='' && $match!=='"'.$revision.'"') { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision precondition does not match.'); }
        return (new Transaction($this->db))->run(function () use ($user,$runId,$stopId,$package,$pairing,$label,$input,$key,$revision,$verifiedHash) {
            $scope='final-deposit:'.$this->org().':'.$user;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $request=['run_id'=>$runId,'stop_id'=>$stopId]+$input;
            ksort($request);
            $hash=$this->crypto->digest('final-deposit-request',json_encode($request,JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was already used for other details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $row=$this->q("SELECT r.state AS run_state,r.revision,r.departed_at,rs.state AS stop_state,rs.location_id,
                    mi.state AS item_state,p.version AS package_version,p.state AS package_state,p.custodian_type,p.custodian_ref,
                    p.width_mm,p.height_mm,p.depth_mm,p.weight_g,s.destination_location_id,si.destination_location_id AS si_destination,
                    k.id AS locker_id,k.capabilities
                FROM route_runs r JOIN drivers d ON d.id=r.driver_id
                JOIN route_run_stops rs ON rs.run_id=r.id AND rs.id=?
                JOIN manifest_items mi ON mi.run_id=r.id AND mi.stop_id=rs.id AND mi.package_id=?
                JOIN packages p ON p.id=mi.package_id JOIN shipments s ON s.id=p.shipment_id
                JOIN shipping_identifiers si ON si.package_id=p.id
                JOIN lockers k ON k.location_id=rs.location_id
                JOIN locations l ON l.id=k.location_id AND l.site_mode='DELIVERY_ONLY' AND l.status='ACTIVE'
                WHERE r.id=? AND r.kind='OUTBOUND' AND r.organization_id=? AND d.user_id=? AND s.organization_id=?
                  AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)
                FOR UPDATE OF r,rs,mi,p",[$stopId,$package,$runId,$this->org(),$user,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'DEPOSIT_NOT_FOUND','Parcel is not assigned to this driver and stop.'); }
            if ((int)$row['revision']!==$revision) { throw new Failure(409,'RUN_REVISION_CONFLICT','Run changed. Refresh before deposit.'); }
            if ((int)$row['package_version']!==$input['expected_package_version']) { throw new Failure(409,'PACKAGE_VERSION_CONFLICT','Parcel changed. Refresh before deposit.'); }
            $driver=(string)$this->q('SELECT id FROM drivers WHERE user_id=?',[$user])->fetchColumn();
            if ($row['run_state']!=='IN_PROGRESS' || $row['departed_at']===null || $row['stop_state']!=='ARRIVED' || $row['item_state']!=='LOADED'
                || $row['package_state']!=='OUTBOUND_CUSTODY' || $row['custodian_type']!=='DRIVER' || $row['custodian_ref']!==$driver) {
                throw new Failure(409,'DEPOSIT_NOT_READY','Parcel must remain loaded in the arrived driver run.');
            }
            if ($row['location_id']!==$row['destination_location_id'] || $row['location_id']!==$row['si_destination']) {
                throw new Failure(409,'WRONG_DESTINATION','Stop and shipping identifier must match the destination locker.');
            }
            $cap=json_decode($row['capabilities'] ?? '{}',true);
            if (!is_array($cap) || ($cap['physical_commands_enabled'] ?? false)!==true || ($cap['synthetic'] ?? true)!==false) {
                throw new Failure(409,'PHYSICAL_LOCKER_REQUIRED','Destination is not enabled for physical commands.');
            }
            $token=$this->q("SELECT encode(token_hash,'hex') FROM package_labels WHERE package_id=? AND status='ACTIVE' AND expires_at>now()",[$package])->fetchColumn();
            if (!$token || !hash_equals((string)$token,$verifiedHash ?? hash('sha256',$label))) { throw new Failure(422,'LABEL_REJECTED','Scan the active parcel label.'); }
            if ($this->q("SELECT 1 FROM locker_sessions WHERE package_id=? AND action='FINAL_DEPOSIT' AND status IN ('READY','OPEN','CLOSED','UNKNOWN','CONFIRMED') LIMIT 1",[$package])->fetchColumn()) {
                throw new Failure(409,'SESSION_EXISTS','A final deposit session already exists; reconcile it before retrying.');
            }
            $pair=$this->q("SELECT tp.device_id FROM terminal_pairing_sessions tp JOIN locker_devices d ON d.id=tp.device_id
                WHERE tp.id=? AND tp.actor_user_id=? AND tp.workflow='FINAL_DEPOSIT' AND tp.status='APPROVED' AND tp.expires_at>now()
                  AND d.locker_id=? AND d.status='ACTIVE' FOR UPDATE OF tp",[$pairing,$user,$row['locker_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$pair) { throw new Failure(409,'PAIRING_REQUIRED','Approve a current pairing at this destination device.'); }
            if (!$this->q("SELECT 1 FROM device_credentials WHERE device_id=? AND public_key IS NOT NULL AND revoked_at IS NULL
                AND valid_from<=now() AND (expires_at IS NULL OR expires_at>now()) LIMIT 1",[$pair['device_id']])->fetchColumn() && !(new CabinetConfigAuth($this->db))->configured((string)$pair['device_id'])) {
                throw new Failure(409,'DEVICE_NOT_ENROLLED','Destination device has no valid enrolled credential.');
            }
            $sizeClause='';$sizeArgs=[];
            if(isset($input['box_model_id'])) {$sizeClause=' AND EXISTS(SELECT 1 FROM cabinet_box bx WHERE bx.compartment_id=c.id AND bx.box_model_id=?)';$sizeArgs[] = Shipping::id($input['box_model_id']);}
            $comp=$this->q("SELECT c.id,c.code,co.generation FROM compartments c
                JOIN controller_boards cb ON cb.id=c.controller_board_id AND cb.locker_id=c.locker_id
                JOIN compartment_ownership co ON co.compartment_id=c.id AND co.owner='DELIVERY'
                JOIN ownership_manifests om ON om.id=co.manifest_id AND om.locker_id=c.locker_id AND om.generation=co.generation AND om.state='ACTIVE'
                LEFT JOIN compartment_claims cc ON cc.compartment_id=c.id
                WHERE c.locker_id=? AND c.status='AVAILABLE' AND c.door_address IS NOT NULL AND cc.id IS NULL
                  AND c.width_mm>=? AND c.height_mm>=? AND c.depth_mm>=? AND c.max_weight_g>=? $sizeClause
                  AND om.generation=(SELECT max(generation) FROM ownership_manifests WHERE locker_id=c.locker_id AND state='ACTIVE')
                ORDER BY c.width_mm*c.height_mm*c.depth_mm,c.id LIMIT 1 FOR UPDATE OF c SKIP LOCKED",
                [$row['locker_id'],$row['width_mm'],$row['height_mm'],$row['depth_mm'],$row['weight_g'],...$sizeArgs])->fetch(PDO::FETCH_ASSOC);
            if (!$comp) { throw new Failure(409,'NO_COMPARTMENT','No eligible delivery compartment is available.'); }
            $session=(string)$this->q("INSERT INTO locker_sessions(package_id,compartment_id,actor_user_id,action,status,expires_at,credential_hash,evidence_policy,pairing_id,expected_package_version,ownership_generation,workflow_context)
                VALUES (?,?,?,'FINAL_DEPOSIT','READY',now()+interval '10 minutes',decode(?,'hex'),'ENROLLED_DOOR_PLUS_ACTOR',?,?,?,?::jsonb) RETURNING id",
                [$package,$comp['id'],$user,$token,$pairing,$row['package_version'],$comp['generation'],json_encode(['run_id'=>$runId,'stop_id'=>$stopId,'expected_revision'=>$revision],JSON_THROW_ON_ERROR)])->fetchColumn();
            $this->q("INSERT INTO compartment_claims(compartment_id,package_id,session_id,state,expires_at) VALUES (?,?,?,'HELD',now()+interval '10 minutes')",[$comp['id'],$package,$session]);
            $command=Secrets::uuid();
            $this->q("INSERT INTO device_commands(session_id,device_id,command_uuid,status,expires_at,ownership_generation)
                VALUES (?,?,?,'PENDING',now()+interval '10 minutes',?)",[$session,$pair['device_id'],$command,$comp['generation']]);
            $this->q("UPDATE terminal_pairing_sessions SET status='CONSUMED' WHERE id=?",[$pairing]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'FINAL_DEPOSIT_PREPARED','locker_session',?)",[$user,$session]);
            $result=['session_id'=>$session,'command_id'=>$command,'compartment_code'=>$comp['code'],'status'=>'READY',
                'package_version'=>(int)$row['package_version'],'run_revision'=>$revision,'custody_transferred'=>false,'awaiting_device_evidence'=>true];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    public function confirm(string $user,string $runId,string $stopId,string $sessionId,array $input,string $key,string $match=''): array
    {
        $this->identity->requireRole($user,'DRIVER');
        Shipping::id($runId); Shipping::id($stopId); Shipping::id($sessionId);
        Input::fields($input,['placed','expected_package_version','expected_revision']);
        Input::text($key,16,100);
        if ($input['placed']!==true || !is_int($input['expected_package_version']) || $input['expected_package_version']<0
            || !is_int($input['expected_revision']) || $input['expected_revision']<1) {
            throw new Failure(422,'INVALID_INPUT','Placement and current revisions are required.');
        }
        $revision=$input['expected_revision'];
        if ($match!=='' && $match!=='"'.$revision.'"') { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision precondition does not match.'); }
        return (new Transaction($this->db))->run(function () use ($user,$runId,$stopId,$sessionId,$input,$key,$revision) {
            $scope='final-deposit-confirm:'.$this->org().':'.$user.':'.$sessionId;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $request=['run_id'=>$runId,'stop_id'=>$stopId,'session_id'=>$sessionId]+$input;
            ksort($request);
            $hash=$this->crypto->digest('final-deposit-confirm',json_encode($request,JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was already used for other details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $row=$this->q("SELECT ls.package_id,ls.compartment_id,ls.status AS session_status,ls.expected_package_version,ls.ownership_generation,
                    dc.id AS command_id,dc.status AS command_status,dc.device_id,dc.ownership_generation AS command_generation,
                    cc.id AS claim_id,cc.state AS claim_state,c.status AS compartment_status,c.door_address,
                    co.owner,co.generation,om.state AS ownership_state,k.id AS locker_id,k.location_id,
                    p.state AS package_state,p.version AS package_version,p.custodian_type,p.custodian_ref,
                    s.destination_location_id,si.destination_location_id AS si_destination,
                    r.state AS run_state,r.revision,r.departed_at,r.driver_id,rs.state AS stop_state,mi.state AS item_state
                FROM locker_sessions ls JOIN device_commands dc ON dc.session_id=ls.id
                JOIN compartments c ON c.id=ls.compartment_id JOIN lockers k ON k.id=c.locker_id
                JOIN locations l ON l.id=k.location_id AND l.site_mode='DELIVERY_ONLY' AND l.status='ACTIVE'
                JOIN compartment_claims cc ON cc.session_id=ls.id AND cc.package_id=ls.package_id AND cc.compartment_id=c.id
                JOIN compartment_ownership co ON co.compartment_id=c.id
                JOIN ownership_manifests om ON om.id=co.manifest_id AND om.locker_id=k.id
                JOIN packages p ON p.id=ls.package_id JOIN shipments s ON s.id=p.shipment_id
                JOIN shipping_identifiers si ON si.package_id=p.id
                JOIN route_runs r ON r.id=? JOIN drivers d ON d.id=r.driver_id AND d.user_id=?
                JOIN route_run_stops rs ON rs.id=? AND rs.run_id=r.id AND rs.location_id=k.location_id
                JOIN manifest_items mi ON mi.run_id=r.id AND mi.stop_id=rs.id AND mi.package_id=p.id
                WHERE ls.id=? AND ls.actor_user_id=? AND ls.action='FINAL_DEPOSIT'
                  AND ls.evidence_policy='ENROLLED_DOOR_PLUS_ACTOR' AND r.kind='OUTBOUND'
                  AND r.organization_id=? AND s.organization_id=?
                  AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)
                FOR UPDATE OF ls,dc,cc,p,r,rs,mi",[$runId,$user,$stopId,$sessionId,$user,$this->org(),$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'DEPOSIT_NOT_FOUND','Final deposit session not found for this driver and stop.'); }
            if ((int)$row['revision']!==$revision) { throw new Failure(409,'RUN_REVISION_CONFLICT','Run changed. Refresh before confirmation.'); }
            if ((int)$row['package_version']!==$input['expected_package_version']) { throw new Failure(409,'PACKAGE_VERSION_CONFLICT','Parcel changed. Refresh before confirmation.'); }
            if ($row['run_state']!=='IN_PROGRESS' || $row['departed_at']===null || $row['stop_state']!=='ARRIVED'
                || $row['item_state']!=='LOADED' || $row['session_status']!=='CLOSED' || $row['command_status']!=='CLOSE_OBSERVED'
                || $row['claim_state']!=='HELD' || $row['package_state']!=='OUTBOUND_CUSTODY'
                || $row['custodian_type']!=='DRIVER' || $row['custodian_ref']!==(string)$row['driver_id']
                || (int)$row['expected_package_version']!==(int)$row['package_version']
                || $row['destination_location_id']!==$row['location_id'] || $row['si_destination']!==$row['location_id']) {
                throw new Failure(409,'DEPOSIT_NOT_CONFIRMED','Closed-door evidence and current driver custody are required.');
            }
            if ($row['owner']!=='DELIVERY' || $row['ownership_state']!=='ACTIVE'
                || (int)$row['generation']!==(int)$row['ownership_generation']
                || (int)$row['generation']!==(int)$row['command_generation']
                || $row['compartment_status']!=='AVAILABLE' || $row['door_address']===null) {
                throw new Failure(409,'OWNERSHIP_CHANGED','Destination compartment ownership changed.');
            }
            $events=$this->q("SELECT id,evidence->>'event_type' AS type,evidence->>'source' AS source FROM device_events
                WHERE command_id=? AND device_id=? AND evidence->>'source' IN ('SIGNED_TERMINAL_REPORT','CABINET_TOKEN_REPORT')
                ORDER BY id",[$row['command_id'],$row['device_id']])->fetchAll(PDO::FETCH_ASSOC);
            if (array_column($events,'type')!==['DISPATCH_RECORDED','OPEN_OBSERVED','CLOSE_OBSERVED']) {
                throw new Failure(409,'EVIDENCE_INCOMPLETE','Ordered signed terminal observations are required.');
            }
            $this->q("UPDATE compartment_claims SET state='OCCUPIED',expires_at=NULL WHERE id=? AND state='HELD'",[$row['claim_id']]);
            $newVersion=(int)$row['package_version']+1;
            $this->q("UPDATE packages SET state='AT_DESTINATION',custodian_type='LOCKER',custodian_ref=?,current_location_id=?,version=? WHERE id=?",
                [$row['locker_id'],$row['location_id'],$newVersion,$row['package_id']]);
            $this->q("UPDATE locker_sessions SET status='CONFIRMED',actor_attested_at=now(),version=version+1 WHERE id=?",[$sessionId]);
            $evidence=['locker_session_id'=>$sessionId,'device_event_ids'=>array_map('strval',array_column($events,'id')),
                'actor_attested'=>true,'source'=>$events[0]['source'],'physical_hardware_verified'=>false];
            $json=json_encode($evidence,JSON_THROW_ON_ERROR);
            $this->q("INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at)
                VALUES (?,?,?,?,'FINAL_DEPOSIT','DRIVER',?,'LOCKER',?,?,?::jsonb,now())",
                [$row['package_id'],Secrets::uuid(),$newVersion,$user,$row['driver_id'],$row['locker_id'],$row['location_id'],$json]);
            $scan=(string)$this->q("INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at)
                VALUES (?,?,?,?,'FINAL_DEPOSIT','ACCEPTED',now()) RETURNING id",
                [Secrets::uuid(),$row['package_id'],$user,$runId])->fetchColumn();
            $this->q("INSERT INTO scan_evidence(scan_event_id,locker_session_id,device_event_id,assurance_level,actor_attestation)
                VALUES (?,?,?,'ENROLLED_DOOR_PLUS_ACTOR',?::jsonb)",[$scan,$sessionId,$events[2]['id'],json_encode(['placed'=>true],JSON_THROW_ON_ERROR)]);
            $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at)
                VALUES (?,?, 'FINAL_DEPOSIT_CONFIRMED',?,?::jsonb,now())",[$row['package_id'],Secrets::uuid(),$user,$json]);
            (new Outbox($this->db))->append(Secrets::uuid(),'package',(string)$row['package_id'],'custody.at_destination',
                ['package_id'=>(string)$row['package_id'],'location_id'=>(string)$row['location_id'],'new_version'=>$newVersion]);
            $stopCompleted=!$this->q("SELECT 1 FROM manifest_items mi JOIN packages p ON p.id=mi.package_id
                WHERE mi.run_id=? AND mi.stop_id=? AND p.state NOT IN ('AT_DESTINATION','COLLECTED') LIMIT 1",[$runId,$stopId])->fetchColumn();
            if ($stopCompleted) {
                $this->q("UPDATE route_run_stops SET state='COMPLETED' WHERE id=?",[$stopId]);
                $this->q("UPDATE route_runs SET revision=revision+1,
                    state=CASE WHEN NOT EXISTS (SELECT 1 FROM route_run_stops WHERE run_id=? AND state<>'COMPLETED') THEN 'COMPLETED' ELSE state END
                    WHERE id=?",[$runId,$runId]);
            }
            $runRevision=(int)$this->q('SELECT revision FROM route_runs WHERE id=?',[$runId])->fetchColumn();
            $result=['session_id'=>$sessionId,'package_id'=>(string)$row['package_id'],'package_state'=>'AT_DESTINATION',
                'package_version'=>$newVersion,'custody_transferred'=>true,'stop_completed'=>$stopCompleted,'run_revision'=>$runRevision];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
