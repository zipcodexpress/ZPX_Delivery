<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;
use Zpx\Shipping\Service as Shipping;

/** Phone-authorized sessions. Signed door observations alone never transfer custody. */
final class PhysicalSessions
{
    public const ACTIONS=['ORIGIN_DEPOSIT','INBOUND_PICKUP','RECIPIENT_PICKUP'];
    public function __construct(private PDO $db,private Secrets $crypto) {}
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql);$q->execute($args);return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function once(string $user,string $action,string $key,array $input,callable $work): array {
        Input::text($key,16,100);
        return (new Transaction($this->db))->run(function () use ($user,$action,$key,$input,$work) {
            $scope='physical-session:'.$this->org().':'.$user.':'.$action;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            ksort($input);$hash=$this->crypto->digest('physical-session',json_encode($input,JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','Request key already used for other details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $result=$work();
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
    private function actor(string $user,string $action): void {
        $identity=new Identity($this->db,$this->crypto);
        $identity->requireRole($user,$action==='INBOUND_PICKUP'?'DRIVER':'CUSTOMER');
        $identity->requireVerified($user);
    }
    private function parcel(string $id,bool $lock=true): array {
        $row=$this->q("SELECT p.*,s.sender_user_id,s.organization_id,s.origin_location_id,s.destination_location_id,s.order_status,s.payment_status,si.si
            FROM packages p JOIN shipments s ON s.id=p.shipment_id JOIN shipping_identifiers si ON si.package_id=p.id
            WHERE p.id=? AND s.organization_id=?".($lock?' FOR UPDATE OF p,s':''),[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(404,'PACKAGE_NOT_FOUND','Parcel unavailable.'); }
        return $row;
    }
    public function issueGrant(string $user,array $input,string $key): array {
        Input::fields($input,['package_id','expected_package_version']);
        $package=Shipping::id($input['package_id']); $this->version($input);
        $this->actor($user,'RECIPIENT_PICKUP');
        return $this->once($user,'grant',$key,$input,function () use ($user,$input,$package) {
            $row=$this->parcel($package);
            $this->recipient($user,$row);
            if ($row['state']!=='AT_DESTINATION' || $row['custodian_type']!=='LOCKER' || (int)$row['version']!==$input['expected_package_version']) {
                throw new Failure(409,'PICKUP_NOT_READY','Refresh the parcel; destination custody is required.');
            }
            if ($this->q("SELECT 1 FROM locker_sessions WHERE package_id=? AND action='RECIPIENT_PICKUP' AND status IN ('READY','OPEN','CLOSED','UNKNOWN')",[$package])->fetchColumn()) {
                throw new Failure(409,'SESSION_EXISTS','An existing collection must be resolved first.');
            }
            $token=Secrets::token();
            $id=(string)$this->q("INSERT INTO pickup_grants(package_id,user_id,location_id,grant_hash,action,expires_at) VALUES (?,?,?,decode(?,'hex'),'RECIPIENT_PICKUP',now()+interval '10 minutes') RETURNING id",[$package,$user,$row['destination_location_id'],hash('sha256',$token)])->fetchColumn();
            return ['grant_id'=>$id,'pickup_token'=>$token,'expires_in_seconds'=>600];
        });
    }
    private function recipient(string $user,array $row): void {
        if (!$this->q("SELECT 1 FROM shipment_parties WHERE shipment_id=? AND party_role='RECIPIENT' AND user_id=?",[$row['shipment_id'],$user])->fetchColumn()) {
            throw new Failure(404,'PACKAGE_NOT_FOUND','Parcel unavailable for this recipient.');
        }
    }
    private function version(array $input): void {
        if (!is_int($input['expected_package_version']) || $input['expected_package_version']<0) { throw new Failure(422,'INVALID_INPUT','A current parcel version is required.'); }
    }
    private function eligibility(string $user,string $action,array $p,string $location,array $context,bool $lock=true): void {
        if ($action==='ORIGIN_DEPOSIT') {
            if ($p['sender_user_id']!==$user || $p['state']!=='CREATED' || $p['custodian_type']!=='SENDER' || $p['custodian_ref']!==$user
                || $p['origin_location_id']!==$location || $p['order_status']!=='READY' || $p['payment_status']!=='PAID') {
                throw new Failure(409,'ORIGIN_NOT_READY','A paid shipment belonging to this sender and origin is required.');
            }
            if ($this->q("SELECT 1 FROM payments py JOIN pricing_quotes q ON q.id=py.quote_id WHERE py.shipment_id=? AND py.status='PENDING' AND q.purpose='ORIGIN_UPGRADE'",[$p['shipment_id']])->fetchColumn()) {
                throw new Failure(409,'PAYMENT_PENDING','Complete the size adjustment before deposit.');
            }
        } elseif ($action==='INBOUND_PICKUP') {
            $r=$this->q("SELECT r.id,r.revision,d.id AS driver_id FROM route_runs r JOIN drivers d ON d.id=r.driver_id
                JOIN route_run_stops rs ON rs.run_id=r.id AND rs.id=? AND rs.location_id=?
                JOIN manifest_items mi ON mi.run_id=r.id AND mi.stop_id=rs.id AND mi.package_id=? AND mi.state='EXPECTED'
                JOIN pickup_demands pd ON pd.package_id=mi.package_id AND pd.assigned_run_id=r.id AND pd.status='ASSIGNED'
                WHERE r.id=? AND r.organization_id=? AND d.user_id=? AND r.kind='INBOUND' AND r.state IN ('ACKNOWLEDGED','IN_PROGRESS')
                  AND rs.state='ARRIVED'".($lock?' FOR UPDATE OF r,rs,mi,pd':''),[$context['stop_id'],$location,$p['id'],$context['run_id'],$this->org(),$user])->fetch(PDO::FETCH_ASSOC);
            if (!$r || (int)$r['revision']!==$context['expected_revision'] || $p['state']!=='AT_ORIGIN'
                || $p['custodian_type']!=='LOCKER' || $p['origin_location_id']!==$location || $p['current_location_id']!==$location) {
                throw new Failure(409,'PICKUP_ASSIGNMENT_CHANGED','An arrived, assigned inbound stop and current locker custody are required.');
            }
        } elseif ($action==='RECIPIENT_PICKUP') {
            $this->recipient($user,$p);
            if ($p['state']!=='AT_DESTINATION' || $p['custodian_type']!=='LOCKER' || $p['destination_location_id']!==$location || $p['current_location_id']!==$location) {
                throw new Failure(409,'PICKUP_NOT_READY','Parcel is not ready at this destination.');
            }
            if (!$this->q("SELECT 1 FROM pickup_grants WHERE id=? AND package_id=? AND user_id=? AND location_id=? AND action='RECIPIENT_PICKUP' AND consumed_at IS NOT NULL",[$context['grant_id'],$p['id'],$user,$location])->fetchColumn()) {
                throw new Failure(409,'GRANT_REQUIRED','A consumed, bound collection grant is required.');
            }
        }
    }
    public function prepare(string $user,array $input,string $key): array { return $this->prepareVerified($user,$input,$key); }
    private function prepareVerified(string $user,array $input,string $key,?string $verifiedHash=null): array {
        Input::fields($input,['workflow','package_id','pairing_id','expected_package_version'],['label_payload','pickup_token','run_id','stop_id','expected_revision','box_model_id']);
        $action=$input['workflow'];
        if (!is_string($action) || !in_array($action,self::ACTIONS,true)) { throw new Failure(422,'INVALID_WORKFLOW','Unsupported parcel workflow.'); }
        $package=Shipping::id($input['package_id']);$pairing=Shipping::id($input['pairing_id']);$this->version($input);$this->actor($user,$action);
        $context=[];
        if ($action==='INBOUND_PICKUP') {
            foreach (['run_id','stop_id'] as $field) { $context[$field]=Shipping::id($input[$field] ?? ''); }
            if (!is_int($input['expected_revision'] ?? null) || $input['expected_revision']<1) { throw new Failure(422,'INVALID_INPUT','Run revision required.'); }
            $context['expected_revision']=$input['expected_revision'];
        }
        $token=Input::text($input[$action==='RECIPIENT_PICKUP'?'pickup_token':'label_payload'] ?? '',1,500);
        $tokenHash=$verifiedHash ?? hash('sha256',$token);
        return $this->once($user,'prepare',$key,$input,function () use ($user,$action,$package,$pairing,$input,$context,$token,$tokenHash) {
            $p=$this->parcel($package);
            if ((int)$p['version']!==$input['expected_package_version']) { throw new Failure(409,'PACKAGE_VERSION_CONFLICT','Refresh the parcel before starting.'); }
            $pair=$this->q("SELECT tp.device_id,k.id AS locker_id,l.id AS location_id FROM terminal_pairing_sessions tp
                JOIN locker_devices d ON d.id=tp.device_id AND d.status='ACTIVE' JOIN lockers k ON k.id=d.locker_id
                JOIN locations l ON l.id=k.location_id AND l.organization_id=? AND l.status='ACTIVE' AND l.site_mode='DELIVERY_ONLY'
                WHERE tp.id=? AND tp.actor_user_id=? AND tp.workflow=? AND tp.status='APPROVED' AND tp.expires_at>now()
                  AND k.capabilities->>'physical_commands_enabled'='true' AND k.capabilities->>'synthetic'='false'
                  AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)
                  AND (EXISTS (SELECT 1 FROM device_credentials dc WHERE dc.device_id=d.id AND dc.revoked_at IS NULL AND dc.valid_from<=now() AND (dc.expires_at IS NULL OR dc.expires_at>now())) OR EXISTS (SELECT 1 FROM cabinet c WHERE c.bound_locker_id=d.locker_id AND c.status='BOUND' AND c.organization_id=l.organization_id AND d.external_device_id='terminal452-cabinet-'||c.cabinet_id::text AND coalesce(c.api_key,'')<>'' AND coalesce(c.api_secret,'')<>''))
                FOR UPDATE OF tp",[$this->org(),$pairing,$user,$action])->fetch(PDO::FETCH_ASSOC);
            if (!$pair) { throw new Failure(409,'PAIRING_REQUIRED','Approve a current enrolled terminal pairing at the exact site.'); }
            if ($action==='RECIPIENT_PICKUP') {
                $grant=$this->q("UPDATE pickup_grants SET consumed_at=now() WHERE grant_hash=decode(?,'hex') AND package_id=? AND user_id=? AND location_id=? AND action='RECIPIENT_PICKUP' AND expires_at>now() AND consumed_at IS NULL RETURNING id",[hash('sha256',$token),$package,$user,$pair['location_id']])->fetchColumn();
                if (!$grant) { throw new Failure(422,'GRANT_REJECTED','Use your current one-use pickup grant.'); }
                $context['grant_id']=(string)$grant;
            } elseif (!$this->q("SELECT 1 FROM package_labels WHERE package_id=? AND token_hash=decode(?,'hex') AND status='ACTIVE' AND expires_at>now()",[$package,$tokenHash])->fetchColumn()) {
                throw new Failure(422,'LABEL_REJECTED','Scan the current primary parcel label.');
            }
            $this->eligibility($user,$action,$p,$pair['location_id'],$context);
            if ($this->q("SELECT 1 FROM locker_sessions WHERE package_id=? AND status IN ('READY','OPEN','CLOSED','UNKNOWN')",[$package])->fetchColumn()) {
                throw new Failure(409,'SESSION_EXISTS','Reconcile the existing parcel session before another attempt.');
            }
            $args=[$pair['locker_id']];
            $where=$action==='ORIGIN_DEPOSIT'?'cc.id IS NULL AND c.width_mm>=? AND c.height_mm>=? AND c.depth_mm>=? AND c.max_weight_g>=?':"cc.package_id=? AND cc.state='OCCUPIED'";
            if ($action==='ORIGIN_DEPOSIT') { array_push($args,$p['width_mm'],$p['height_mm'],$p['depth_mm'],$p['weight_g']); }
            else { $args[]=$package; }
            if(isset($input['box_model_id'])) {
                if($action!=='ORIGIN_DEPOSIT') throw new Failure(422,'INVALID_INPUT','Only deposits select another size.');
                $model=Shipping::id($input['box_model_id']);
                $where.=' AND EXISTS(SELECT 1 FROM cabinet_box bx WHERE bx.compartment_id=c.id AND bx.box_model_id=?)';$args[]=$model;
            }
            $door=$this->q("SELECT c.id,c.code,co.generation FROM compartments c JOIN controller_boards cb ON cb.id=c.controller_board_id AND cb.locker_id=c.locker_id
                JOIN compartment_ownership co ON co.compartment_id=c.id AND co.owner='DELIVERY'
                JOIN ownership_manifests om ON om.id=co.manifest_id AND om.locker_id=c.locker_id AND om.generation=co.generation AND om.state='ACTIVE'
                LEFT JOIN compartment_claims cc ON cc.compartment_id=c.id
                WHERE c.locker_id=? AND c.status='AVAILABLE' AND c.door_address IS NOT NULL AND cb.protocol_profile<>'UNVERIFIED' AND $where
                  AND om.generation=(SELECT max(generation) FROM ownership_manifests WHERE locker_id=c.locker_id AND state='ACTIVE')
                ORDER BY c.width_mm::bigint*c.height_mm*c.depth_mm,c.id LIMIT 1 FOR UPDATE OF c SKIP LOCKED",$args)->fetch(PDO::FETCH_ASSOC);
            if (!$door || ($action!=='ORIGIN_DEPOSIT' && $p['custodian_ref']!==$pair['locker_id'])) { throw new Failure(409,'NO_COMPARTMENT','No eligible, owned compartment matches this parcel.'); }
            $session=(string)$this->q("INSERT INTO locker_sessions(package_id,compartment_id,actor_user_id,action,status,expires_at,credential_hash,evidence_policy,pairing_id,expected_package_version,ownership_generation,workflow_context)
                VALUES (?,?,?,?,'READY',now()+interval '10 minutes',decode(?,'hex'),'ENROLLED_DOOR_PLUS_ACTOR',?,?,?,?::jsonb) RETURNING id",[$package,$door['id'],$user,$action,$tokenHash,$pairing,$p['version'],$door['generation'],json_encode((object)$context,JSON_THROW_ON_ERROR)])->fetchColumn();
            if ($action==='ORIGIN_DEPOSIT') {
                $this->q("INSERT INTO compartment_claims(compartment_id,package_id,session_id,state,expires_at) VALUES (?,?,?,'HELD',now()+interval '10 minutes')",[$door['id'],$package,$session]);
            } else { $this->q("UPDATE compartment_claims SET session_id=? WHERE compartment_id=? AND package_id=? AND state='OCCUPIED'",[$session,$door['id'],$package]); }
            $command=Secrets::uuid();
            $this->q("INSERT INTO device_commands(session_id,device_id,command_uuid,status,expires_at,ownership_generation) VALUES (?,?,?,'PENDING',now()+interval '10 minutes',?)",[$session,$pair['device_id'],$command,$door['generation']]);
            $this->q("UPDATE terminal_pairing_sessions SET status='CONSUMED' WHERE id=?",[$pairing]);
            return ['session_id'=>$session,'command_id'=>$command,'status'=>'READY','workflow'=>$action,'compartment_code'=>$door['code'],'package_version'=>(int)$p['version'],'custody_transferred'=>false];
        });
    }
    public function authorizeCommand(string $session,bool $lock=true): array {
        $row=$this->q("SELECT ls.*,encode(ls.credential_hash,'hex') AS credential_hex,dc.id AS command_pk,dc.command_uuid,dc.status AS command_status,dc.device_id,dc.expires_at AS command_expiry,
                encode(dc.payload_hash,'hex') AS payload_hash,dc.ownership_generation AS command_generation,
                c.code,c.locker_id,c.status AS door_status,c.door_address,cb.board_address,cb.protocol_profile,k.location_id,
                co.owner,co.generation,om.state AS ownership_state,cc.state AS claim_state,tp.status AS pairing_status,tp.actor_user_id AS paired_actor
            FROM locker_sessions ls JOIN device_commands dc ON dc.session_id=ls.id JOIN compartments c ON c.id=ls.compartment_id
            JOIN controller_boards cb ON cb.id=c.controller_board_id AND cb.locker_id=c.locker_id
            JOIN lockers k ON k.id=c.locker_id JOIN locations l ON l.id=k.location_id
            JOIN compartment_ownership co ON co.compartment_id=c.id JOIN ownership_manifests om ON om.id=co.manifest_id AND om.locker_id=c.locker_id AND om.generation=co.generation
            JOIN compartment_claims cc ON cc.compartment_id=c.id AND cc.package_id=ls.package_id AND cc.session_id=ls.id
            JOIN terminal_pairing_sessions tp ON tp.id=ls.pairing_id AND tp.device_id=dc.device_id
            WHERE ls.id=? AND ls.evidence_policy='ENROLLED_DOOR_PLUS_ACTOR' AND l.organization_id=? AND l.status='ACTIVE' AND l.site_mode='DELIVERY_ONLY'
              AND k.capabilities->>'physical_commands_enabled'='true' AND k.capabilities->>'synthetic'='false'
              AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)
              AND co.generation=(SELECT max(generation) FROM ownership_manifests WHERE locker_id=c.locker_id AND state='ACTIVE')".($lock?' FOR UPDATE OF ls,dc,c,cc':''),[$session,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$row || !in_array($row['action'],self::ACTIONS,true) || $row['owner']!=='DELIVERY' || $row['ownership_state']!=='ACTIVE' || $row['door_status']!=='AVAILABLE' || $row['door_address']===null
            || $row['protocol_profile']==='UNVERIFIED' || $row['pairing_status']!=='CONSUMED' || $row['paired_actor']!==$row['actor_user_id']
            || (int)$row['generation']!==(int)$row['ownership_generation'] || (int)$row['generation']!==(int)$row['command_generation']
            || $row['claim_state']!==($row['action']==='ORIGIN_DEPOSIT'?'HELD':'OCCUPIED')) {
            throw new Failure(409,'COMMAND_STALE','Compartment authorization changed; request assistance.');
        }
        $p=$this->parcel((string)$row['package_id'],$lock);
        if (!in_array($row['protocol_profile'],['SIMULATED_24','TERMINAL452_V1','TERMINAL452_V2'],true) || ($row['protocol_profile']==='SIMULATED_24'&&!in_array(getenv('APP_ENV'),['development','test'],true))) {
            throw new Failure(409,'PROFILE_UNAVAILABLE','Controller profile must match the configured Terminal452 protocol.');
        }
        if ($row['action']!=='RECIPIENT_PICKUP' && !$this->q("SELECT 1 FROM package_labels WHERE package_id=? AND token_hash=decode(?,'hex') AND status='ACTIVE' AND expires_at>now()",[$row['package_id'],$row['credential_hex']])->fetchColumn()) {
            throw new Failure(409,'LABEL_REJECTED','The parcel label changed during this operation.');
        }
        if ($row['payload_hash']!==null && !hash_equals($row['payload_hash'],hash('sha256',json_encode($this->payload($row),JSON_THROW_ON_ERROR)))) {
            throw new Failure(409,'COMMAND_PAYLOAD_CHANGED','Physical address or command policy changed after authorization.');
        }
        if ((int)$p['version']!==(int)$row['expected_package_version']) { throw new Failure(409,'PACKAGE_VERSION_CONFLICT','Parcel changed during this operation.'); }
        $this->actor((string)$row['actor_user_id'],$row['action']);
        $this->eligibility((string)$row['actor_user_id'],$row['action'],$p,$row['location_id'],json_decode($row['workflow_context'],true,512,JSON_THROW_ON_ERROR),$lock);
        return $row;
    }
    private function payload(array $r): array {
        return ['command_id'=>$r['command_uuid'],'session_id'=>(string)$r['id'],'action'=>'OPEN','address'=>['locker_id'=>(string)$r['locker_id'],'board_address'=>(int)$r['board_address'],'door_address'=>(int)$r['door_address']],
            'protocol_profile'=>$r['protocol_profile'],'ownership_generation'=>(int)$r['generation'],'expires_at'=>$r['command_expiry']];
    }
    public function pendingCommands(array $device): array {
        $ids=$this->q("SELECT ls.id FROM locker_sessions ls JOIN device_commands dc ON dc.session_id=ls.id WHERE dc.device_id=? AND ls.action IN ('ORIGIN_DEPOSIT','INBOUND_PICKUP','RECIPIENT_PICKUP') AND ls.status='READY' AND dc.status='PENDING' AND ls.expires_at>now() AND dc.expires_at>now() ORDER BY dc.id LIMIT 20",[$device['id']])->fetchAll(PDO::FETCH_COLUMN);
        $commands=[];
        foreach ($ids as $id) {
            $r=$this->authorizeCommand((string)$id);
            if ($r['locker_id']!==$device['locker_id'] || ($r['protocol_profile']==='SIMULATED_24' && !in_array(getenv('APP_ENV'),['development','test'],true))) { throw new Failure(409,'PROFILE_UNAVAILABLE','Controller profile unavailable.'); }
            $command=$this->payload($r);
            $hash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR));
            if ($r['payload_hash']!==null && !hash_equals($r['payload_hash'],$hash)) { throw new Failure(409,'COMMAND_PAYLOAD_CHANGED','Address changed after polling; reconcile before opening.'); }
            $this->q("UPDATE device_commands SET dispatched_at=COALESCE(dispatched_at,now()),payload_hash=decode(?,'hex') WHERE id=?",[$hash,$r['command_pk']]);
            $commands[]=$command;
        }
        return $commands;
    }
    public function status(string $session,?string $user=null,?string $device=null): array {
        $r=$this->q("SELECT ls.id,ls.pairing_id,ls.package_id,ls.status,ls.action,ls.actor_user_id,ls.expected_package_version,ls.workflow_context,dc.device_id,dc.command_uuid,dc.status AS command_status,
                c.code,p.state AS package_state,p.version AS package_version,si.si
            FROM locker_sessions ls JOIN device_commands dc ON dc.session_id=ls.id JOIN compartments c ON c.id=ls.compartment_id
            JOIN packages p ON p.id=ls.package_id JOIN shipments s ON s.id=p.shipment_id JOIN shipping_identifiers si ON si.package_id=p.id
            WHERE ls.id=? AND s.organization_id=? AND ls.evidence_policy='ENROLLED_DOOR_PLUS_ACTOR'",[Shipping::id($session),$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$r || ($user!==null && $r['actor_user_id']!==$user) || ($device!==null && $r['device_id']!==$device) || ($user===null && $device===null)) { throw new Failure(404,'SESSION_NOT_FOUND','Session not available.'); }
        return ['session_id'=>(string)$r['id'],'pairing_id'=>(string)$r['pairing_id'],'package_id'=>(string)$r['package_id'],'expected_package_version'=>(int)$r['expected_package_version'],
            'workflow'=>$r['action'],'status'=>$r['status'],'command_id'=>$r['command_uuid'],'command_status'=>$r['command_status'],'workflow_context'=>(object)json_decode($r['workflow_context'],true,512,JSON_THROW_ON_ERROR),
            'compartment_code'=>$r['code'],'si'=>$r['si'],'package_state'=>$r['package_state'],'package_version'=>(int)$r['package_version'],
            'custody_transferred'=>$r['status']==='CONFIRMED'];
    }
    public function scanAtTerminal(string $device,string $pairing,array $input,string $key): array {
        Input::fields($input,['label_payload']);$label=Input::text($input['label_payload'],1,500);Shipping::id($pairing);
        $pair=$this->q("SELECT tp.actor_user_id,tp.workflow,k.location_id FROM terminal_pairing_sessions tp JOIN locker_devices d ON d.id=tp.device_id AND d.status='ACTIVE' JOIN lockers k ON k.id=d.locker_id JOIN locations l ON l.id=k.location_id
            WHERE tp.id=? AND tp.device_id=? AND tp.status IN ('APPROVED','CONSUMED') AND tp.expires_at>now() AND l.organization_id=? AND l.status='ACTIVE' AND l.site_mode='DELIVERY_ONLY'",[$pairing,$device,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if(!$pair||$pair['workflow']==='RECIPIENT_PICKUP') throw new Failure(409,'PAIRING_REQUIRED','Approve the exact sender or carrier workflow before scanning.');
        $p=$this->q("SELECT p.id,p.version FROM package_labels pl JOIN packages p ON p.id=pl.package_id JOIN shipments s ON s.id=p.shipment_id WHERE pl.token_hash=decode(?,'hex') AND pl.status='ACTIVE' AND pl.expires_at>now() AND s.organization_id=?",[hash('sha256',$label),$this->org()])->fetch(PDO::FETCH_ASSOC);
        if(!$p) throw new Failure(422,'LABEL_REJECTED','Scan the active parcel label.');
        $prepared=['workflow'=>$pair['workflow'],'package_id'=>(string)$p['id'],'pairing_id'=>$pairing,'label_payload'=>$label,'expected_package_version'=>(int)$p['version']];
        if($pair['workflow']!=='ORIGIN_DEPOSIT') {
            $runs=$this->q("SELECT r.id,r.revision,rs.id AS stop_id FROM route_runs r JOIN drivers d ON d.id=r.driver_id JOIN route_run_stops rs ON rs.run_id=r.id JOIN manifest_items mi ON mi.run_id=r.id AND mi.stop_id=rs.id WHERE d.user_id=? AND r.organization_id=? AND r.kind=? AND r.state='IN_PROGRESS' AND rs.state='ARRIVED' AND rs.location_id=? AND mi.package_id=? AND mi.state=?",[$pair['actor_user_id'],$this->org(),$pair['workflow']==='FINAL_DEPOSIT'?'OUTBOUND':'INBOUND',$pair['location_id'],$p['id'],$pair['workflow']==='FINAL_DEPOSIT'?'LOADED':'EXPECTED'])->fetchAll(PDO::FETCH_ASSOC);
            if(count($runs)!==1) throw new Failure(409,'PARCEL_NOT_ASSIGNED','Scan a parcel in your arrived assigned stop.');
            $run=$runs[0];$prepared+=['run_id'=>(string)$run['id'],'stop_id'=>(string)$run['stop_id'],'expected_revision'=>(int)$run['revision']];
            if($pair['workflow']==='FINAL_DEPOSIT') {unset($prepared['workflow'],$prepared['run_id'],$prepared['stop_id']);return $this->terminalPrepared((new FinalDeposit($this->db,$this->crypto))->prepare((string)$pair['actor_user_id'],(string)$run['id'],(string)$run['stop_id'],$prepared,$key));}
        }
        return $this->prepare((string)$pair['actor_user_id'],$prepared,$key);
    }
    public function retryDeposit(string $device,string $session,array $input,string $key): array {
        Input::fields($input,['box_model_id']);Shipping::id($input['box_model_id']);
        $status=$this->status($session,null,$device);
        if($status['status']!=='CANCELLED'||!in_array($status['workflow'],['ORIGIN_DEPOSIT','FINAL_DEPOSIT'],true)) throw new Failure(409,'DEPOSIT_RETRY_UNAVAILABLE','Decline the closed deposit attempt before choosing another size.');
        $r=$this->q("SELECT actor_user_id,encode(credential_hash,'hex') AS credential_hex FROM locker_sessions WHERE id=?",[$session])->fetch(PDO::FETCH_ASSOC);
        if($status['workflow']==='FINAL_DEPOSIT') return $this->terminalPrepared((new FinalDeposit($this->db,$this->crypto))->retry((string)$r['actor_user_id'],$session,$input,$key));
        return $this->prepareVerified((string)$r['actor_user_id'],['workflow'=>'ORIGIN_DEPOSIT','package_id'=>$status['package_id'],'pairing_id'=>$status['pairing_id'],'expected_package_version'=>$status['expected_package_version'],'label_payload'=>'retry:'.$session,'box_model_id'=>$input['box_model_id']],$key,$r['credential_hex']);
    }
    private function terminalPrepared(array $r): array { return array_intersect_key($r,array_flip(['session_id','command_id','compartment_code','status','package_version','custody_transferred']))+['workflow'=>'FINAL_DEPOSIT']; }
    public function deviceDecision(string $device,string $session,array $input,string $key,bool $declined): array {
        $status=$this->status($session,null,$device);
        $actor=(string)$this->q('SELECT actor_user_id FROM locker_sessions WHERE id=?',[$session])->fetchColumn();
        if($declined) return $this->didntDeposit($actor,$session,$input,$key);
        if($status['workflow']==='FINAL_DEPOSIT') {
            $ctx=(array)$status['workflow_context'];
            Input::fields($input,['attested','expected_package_version']);
            $result=(new FinalDeposit($this->db,$this->crypto))->confirm($actor,$ctx['run_id'],$ctx['stop_id'],$session,['placed'=>$input['attested'],'expected_package_version'=>$input['expected_package_version'],'expected_revision'=>$ctx['expected_revision']],$key,'"'.$ctx['expected_revision'].'"');
            return array_intersect_key($result,array_flip(['session_id','package_id','package_state','package_version','custody_transferred']));
        }
        return $this->confirm($actor,$session,$input,$key);
    }
    public function didntDeposit(string $user,string $session,array $input,string $key): array {
        Input::fields($input,['expected_package_version']);$this->version($input);Shipping::id($session);
        return $this->once($user,'didnt-deposit:'.$session,$key,$input,function()use($user,$session,$input){
            $status=$this->status($session,$user);
            if (!in_array($status['workflow'],['ORIGIN_DEPOSIT','FINAL_DEPOSIT'],true)) throw new Failure(409,'DEPOSIT_REQUIRED','Only a deposit attempt can be declined.');
            if ($status['status']==='CANCELLED') return ['session_id'=>$session,'attempt_abandoned'=>true,'custody_transferred'=>false];
            $r=$this->q("SELECT ls.*,dc.id AS command_pk,dc.device_id,dc.status AS command_status FROM locker_sessions ls JOIN device_commands dc ON dc.session_id=ls.id WHERE ls.id=? FOR UPDATE OF ls,dc",[$session])->fetch(PDO::FETCH_ASSOC);
            $p=$this->parcel((string)$r['package_id']);
            if ($r['status']!=='CLOSED'||$r['command_status']!=='CLOSE_OBSERVED'||(int)$p['version']!==$input['expected_package_version']||(int)$r['expected_package_version']!==$input['expected_package_version']) throw new Failure(409,'EVIDENCE_INCOMPLETE','Close the door before selecting I did not deposit.');
            $events=$this->q("SELECT evidence->>'event_type' FROM device_events WHERE command_id=? AND device_id=? AND evidence->>'source' IN ('SIGNED_TERMINAL_REPORT','CABINET_TOKEN_REPORT') ORDER BY id",[$r['command_pk'],$r['device_id']])->fetchAll(PDO::FETCH_COLUMN);
            if($events!==['DISPATCH_RECORDED','OPEN_OBSERVED','CLOSE_OBSERVED']) throw new Failure(409,'EVIDENCE_INCOMPLETE','Complete ordered door observations required.');
            if(!$this->q("DELETE FROM compartment_claims WHERE session_id=? AND state='HELD' RETURNING id",[$session])->fetchColumn()) throw new Failure(409,'CLAIM_CONFLICT','Deposit reservation is unavailable.');
            $this->q("UPDATE locker_sessions SET status='CANCELLED',version=version+1 WHERE id=?",[$session]);
            $this->q("UPDATE device_commands SET status='CANCELLED' WHERE id=?",[$r['command_pk']]);
            $this->q("UPDATE terminal_pairing_sessions SET status='APPROVED' WHERE id=? AND status='CONSUMED' AND expires_at>now()",[$r['pairing_id']]);
            $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,'DEPOSIT_DECLINED',?,?::jsonb,now())",[$p['id'],Secrets::uuid(),$user,json_encode(['locker_session_id'=>$session,'custody_transferred'=>false],JSON_THROW_ON_ERROR)]);
            return ['session_id'=>$session,'attempt_abandoned'=>true,'custody_transferred'=>false];
        });
    }
    public function confirm(string $user,string $session,array $input,string $key): array {
        Input::fields($input,['attested','expected_package_version']);$this->version($input);
        if ($input['attested']!==true) { throw new Failure(422,'ATTESTATION_REQUIRED','Confirm actual placement or removal on your phone.'); }
        Shipping::id($session);
        return $this->once($user,'confirm:'.$session,$key,$input,function () use ($user,$session,$input) {
            $status=$this->status($session,$user);
            if (!in_array($status['workflow'],self::ACTIONS,true)) { throw new Failure(409,'USE_DRIVER_CONFIRMATION','Use the assigned run final-deposit confirmation.'); }
            $r=$this->authorizeCommand($session);
            if ($r['status']!=='CLOSED' || $r['command_status']!=='CLOSE_OBSERVED' || (int)$r['expected_package_version']!==$input['expected_package_version']) { throw new Failure(409,'EVIDENCE_INCOMPLETE','Ordered open/close evidence and current parcel version are required.'); }
            $events=$this->q("SELECT id,evidence->>'event_type' AS type,evidence->>'source' AS source FROM device_events WHERE command_id=? AND device_id=? AND evidence->>'source' IN ('SIGNED_TERMINAL_REPORT','CABINET_TOKEN_REPORT') ORDER BY id",[$r['command_pk'],$r['device_id']])->fetchAll(PDO::FETCH_ASSOC);
            if (array_column($events,'type')!==['DISPATCH_RECORDED','OPEN_OBSERVED','CLOSE_OBSERVED']) { throw new Failure(409,'EVIDENCE_INCOMPLETE','Complete ordered signed observations are required.'); }
            $p=$this->parcel((string)$r['package_id']);$ctx=json_decode($r['workflow_context'],true,512,JSON_THROW_ON_ERROR);$version=(int)$p['version']+1;
            $action=$r['action'];$state=$action==='ORIGIN_DEPOSIT'?'AT_ORIGIN':($action==='INBOUND_PICKUP'?'INBOUND_CUSTODY':'COLLECTED');
            $type=$action==='ORIGIN_DEPOSIT'?'LOCKER':($action==='INBOUND_PICKUP'?'DRIVER':'RECIPIENT');
            $ref=$action==='ORIGIN_DEPOSIT'?$r['locker_id']:($action==='INBOUND_PICKUP'?(string)$this->q('SELECT id FROM drivers WHERE user_id=?',[$user])->fetchColumn():$user);
            $this->q('UPDATE packages SET state=?,custodian_type=?,custodian_ref=?,current_location_id=?,version=? WHERE id=?',[$state,$type,$ref,$action==='INBOUND_PICKUP'?null:$r['location_id'],$version,$p['id']]);
            if ($action==='ORIGIN_DEPOSIT') {
                $this->q("UPDATE compartment_claims SET state='OCCUPIED',expires_at=NULL WHERE session_id=?",[$session]);
                $this->q("INSERT INTO pickup_demands(package_id,origin_location_id,status) VALUES (?,?,'OPEN')",[$p['id'],$r['location_id']]);
            } else {
                $this->q('DELETE FROM compartment_claims WHERE session_id=?',[$session]);
                if ($action==='INBOUND_PICKUP') {
                    $this->q("UPDATE manifest_items SET state='LOADED' WHERE package_id=? AND run_id=? AND stop_id=? AND state='EXPECTED'",[$p['id'],$ctx['run_id'],$ctx['stop_id']]);
                    $this->q("UPDATE pickup_demands SET status='RESOLVED',version=version+1 WHERE package_id=? AND assigned_run_id=?",[$p['id'],$ctx['run_id']]);
                    if (!$this->q("SELECT 1 FROM manifest_items WHERE run_id=? AND stop_id=? AND state NOT IN ('LOADED','RELEASED')",[$ctx['run_id'],$ctx['stop_id']])->fetchColumn()) {
                        $this->q("UPDATE route_run_stops SET state='COMPLETED' WHERE id=? AND run_id=? AND state='ARRIVED'",[$ctx['stop_id'],$ctx['run_id']]);
                        $this->q('UPDATE route_runs SET revision=revision+1 WHERE id=?',[$ctx['run_id']]);
                    }
                }
            }
            $this->q("UPDATE locker_sessions SET status='CONFIRMED',actor_attested_at=now(),version=version+1 WHERE id=?",[$session]);
            $evidence=json_encode(['locker_session_id'=>$session,'device_event_ids'=>array_column($events,'id'),'actor_attested'=>true,'source'=>$events[0]['source'],'physical_hardware_verified'=>false],JSON_THROW_ON_ERROR);
            $this->q('INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at) VALUES (?,?,?,?,?,?,?,?,?,?,?::jsonb,now())',[$p['id'],Secrets::uuid(),$version,$user,$action,$p['custodian_type'],$p['custodian_ref'],$type,$ref,$r['location_id'],$evidence]);
            $scan=(string)$this->q("INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at) VALUES (?,?,?,?,?,'ACCEPTED',now()) RETURNING id",[Secrets::uuid(),$p['id'],$user,$ctx['run_id'] ?? null,$action])->fetchColumn();
            $this->q("INSERT INTO scan_evidence(scan_event_id,locker_session_id,device_event_id,assurance_level,actor_attestation) VALUES (?,?,?,'ENROLLED_DOOR_PLUS_ACTOR','{\"attested\":true}')",[$scan,$session,$events[2]['id']]);
            $this->q('INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,?,?,?::jsonb,now())',[$p['id'],Secrets::uuid(),$action.'_CONFIRMED',$user,$evidence]);
            (new Outbox($this->db))->append(Secrets::uuid(),'package',(string)$p['id'],'custody.'.strtolower($action),['package_id'=>(string)$p['id'],'new_version'=>$version]);
            return ['session_id'=>$session,'package_id'=>(string)$p['id'],'package_state'=>$state,'package_version'=>$version,'custody_transferred'=>true];
        });
    }
}
