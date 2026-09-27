<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;
use Zpx\Shipping\Service as Shipping;

/** Development adapter: virtual compartments and synthetic events only. Never commands hardware. */
final class OriginDeposit
{
    private Identity $identity;
    public function __construct(private PDO $db, private Secrets $crypto) { $this->identity=new Identity($db,$crypto); }
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql);$q->execute($args);return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function allowed(): void {
        if (!in_array(getenv('APP_ENV'),['development','test'],true)) { throw new Failure(503,'SIMULATION_DISABLED','Locker simulation is unavailable.'); }
    }
    private function once(string $user,string $action,string $key,array $input,callable $work): array {
        Input::text($key,16,100);
        return (new Transaction($this->db))->run(function () use ($user,$action,$key,$input,$work) {
            $this->allowed();
            $this->identity->requireRole($user,'CUSTOMER'); $this->identity->requireVerified($user);
            $scope='origin-simulation:'.$this->org().':'.$user.':'.$action;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            ksort($input);
            $hash=$this->crypto->digest('origin-request',json_encode($input,JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was already used for other details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $result=$work();
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
    public function start(string $user,array $input,string $key): array {
        Input::fields($input,['package_id','location_id','label_payload','expected_package_version']);
        $package=Shipping::id($input['package_id']); $location=Shipping::id($input['location_id']);
        $label=Input::text($input['label_payload'],1,500);
        if (!is_int($input['expected_package_version']) || $input['expected_package_version']<0) { throw new Failure(422,'INVALID_INPUT','Invalid package version.'); }
        return $this->once($user,'start:'.$package,$key,$input,function () use ($user,$package,$location,$label,$input) {
            (new Shipping($this->db,$this->crypto))->originSizeOptions($user,$package);
            $row=$this->q("SELECT p.*,s.origin_location_id,s.destination_location_id,s.sender_user_id,s.organization_id,s.development_only,s.order_status,s.payment_status,
                    k.id AS locker_id,k.capabilities,d.id AS device_id,si.id AS si_id
                FROM packages p JOIN shipments s ON s.id=p.shipment_id
                JOIN lockers k ON k.location_id=s.origin_location_id
                JOIN locker_devices d ON d.locker_id=k.id AND d.status='SIMULATED'
                JOIN shipping_identifiers si ON si.package_id=p.id AND si.destination_location_id=s.destination_location_id
                WHERE p.id=? AND s.organization_id=? FOR UPDATE OF p,s",[$package,$this->org()])->fetch(PDO::FETCH_ASSOC);
            $capabilities=$row?json_decode($row['capabilities'] ?? '{}',true):[];
            if (!$row || !$row['development_only'] || ($capabilities['synthetic'] ?? false)!==true || ($capabilities['physical_commands_enabled'] ?? true)!==false
                || $row['sender_user_id']!==$user || $row['origin_location_id']!==$location || $row['state']!=='CREATED'
                || $row['order_status']!=='READY' || $row['payment_status']!=='PAID'
                || $row['custodian_type']!=='SENDER' || $row['custodian_ref']!==$user) {
                throw new Failure(409,'ORIGIN_DEPOSIT_UNAVAILABLE','This parcel cannot use simulated origin deposit.');
            }
            if ((int)$row['version']!==$input['expected_package_version']) { throw new Failure(409,'PACKAGE_VERSION_CONFLICT','Refresh the parcel before deposit.'); }
            $token=$this->q("SELECT id,encode(token_hash,'hex') AS hash FROM package_labels WHERE package_id=? AND status='ACTIVE' AND expires_at>now()",[$package])->fetch(PDO::FETCH_ASSOC);
            if (!$token || !hash_equals($token['hash'],hash('sha256',$label))) { throw new Failure(422,'LABEL_REJECTED','Scan the active primary label.'); }
            if ($this->q("SELECT 1 FROM payments p JOIN pricing_quotes q ON q.id=p.quote_id WHERE p.shipment_id=? AND p.status='PENDING' AND q.purpose='ORIGIN_UPGRADE'",[$row['shipment_id']])->fetchColumn()) {
                throw new Failure(409,'PAYMENT_PENDING','Complete the size adjustment before deposit.');
            }
            // Expired, unopened virtual claims can be reclaimed; ambiguous/open commands cannot.
            $expired=$this->q("UPDATE locker_sessions ls SET status='EXPIRED' FROM device_commands dc
                WHERE dc.session_id=ls.id AND ls.package_id=? AND ls.action='ORIGIN_DEPOSIT' AND ls.status='READY'
                  AND ls.expires_at<=now() AND dc.status='PENDING' RETURNING ls.id",[$package])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($expired as $sessionId) {
                $this->q("DELETE FROM compartment_claims WHERE session_id=? AND state='HELD'",[$sessionId]);
                $this->q("UPDATE device_commands SET status='EXPIRED' WHERE session_id=? AND status='PENDING'",[$sessionId]);
            }
            if ($this->q("SELECT 1 FROM locker_sessions WHERE package_id=? AND action='ORIGIN_DEPOSIT' AND status IN ('READY','OPEN','CLOSED','UNKNOWN','CONFIRMED') LIMIT 1",[$package])->fetchColumn()) {
                throw new Failure(409,'SESSION_EXISTS','An origin deposit session already exists for this parcel.');
            }
            $code='SIM-'.substr($row['size_class'],0,1);
            $compartment=$this->q("SELECT c.id,c.code FROM compartments c LEFT JOIN compartment_claims cc ON cc.compartment_id=c.id
                WHERE c.locker_id=? AND c.code=? AND c.status='SIMULATED_AVAILABLE' AND cc.id IS NULL
                  AND c.width_mm>=? AND c.height_mm>=? AND c.depth_mm>=? AND c.max_weight_g>=?
                FOR UPDATE OF c SKIP LOCKED",[$row['locker_id'],$code,$row['width_mm'],$row['height_mm'],$row['depth_mm'],$row['weight_g']])->fetch(PDO::FETCH_ASSOC);
            if (!$compartment) { throw new Failure(409,'NO_COMPARTMENT','No compatible virtual compartment is available.'); }
            $pairing=(string)$this->q("INSERT INTO terminal_pairing_sessions(scene_uuid,device_id,actor_user_id,workflow,status,expires_at) VALUES (?,?,?,'ORIGIN_DEPOSIT','APPROVED',now()+interval '10 minutes') RETURNING id",[Secrets::uuid(),$row['device_id'],$user])->fetchColumn();
            $session=(string)$this->q("INSERT INTO locker_sessions(package_id,compartment_id,actor_user_id,action,status,expires_at,credential_hash,evidence_policy,pairing_id,expected_package_version)
                VALUES (?,?,?,'ORIGIN_DEPOSIT','READY',now()+interval '10 minutes',decode(?,'hex'),'SIMULATED_DOOR_PLUS_ACTOR',?,?) RETURNING id",
                [$package,$compartment['id'],$user,$token['hash'],$pairing,$row['version']])->fetchColumn();
            $this->q("UPDATE terminal_pairing_sessions SET status='CONSUMED' WHERE id=?",[$pairing]);
            $this->q("INSERT INTO compartment_claims(compartment_id,package_id,session_id,state,expires_at) VALUES (?,?,?,'HELD',now()+interval '10 minutes')",[$compartment['id'],$package,$session]);
            $command=Secrets::uuid();
            $this->q("INSERT INTO device_commands(session_id,device_id,command_uuid,status,expires_at) VALUES (?,?,?,'PENDING',now()+interval '10 minutes')",[$session,$row['device_id'],$command]);
            return ['session_id'=>$session,'command_id'=>$command,'compartment_code'=>$compartment['code'],'status'=>'READY',
                'package_version'=>(int)$row['version'],'development_only'=>true,'physical_hardware_verified'=>false];
        });
    }
    private function session(string $user,string $session,bool $lock=true): array {
        Shipping::id($session);
        $row=$this->q("SELECT ls.*,encode(ls.credential_hash,'hex') AS credential_hex,dc.id AS command_id,dc.status AS command_status,dc.device_id,c.code AS compartment_code,
                c.locker_id,k.location_id,s.organization_id,s.development_only,s.order_status,s.payment_status,p.state AS package_state,p.version AS package_version,
                p.custodian_type,p.custodian_ref,p.shipment_id,tp.actor_user_id AS paired_actor
            FROM locker_sessions ls JOIN device_commands dc ON dc.session_id=ls.id
            JOIN compartments c ON c.id=ls.compartment_id JOIN lockers k ON k.id=c.locker_id
            JOIN packages p ON p.id=ls.package_id JOIN shipments s ON s.id=p.shipment_id
            JOIN terminal_pairing_sessions tp ON tp.id=ls.pairing_id
            WHERE ls.id=? AND ls.actor_user_id=? AND s.organization_id=? AND s.sender_user_id=? AND s.origin_location_id=k.location_id
              AND ls.action='ORIGIN_DEPOSIT' AND ls.evidence_policy='SIMULATED_DOOR_PLUS_ACTOR'
              AND c.status='SIMULATED_AVAILABLE' AND k.capabilities->>'synthetic'='true'
              AND k.capabilities->>'physical_commands_enabled'='false'".($lock?' FOR UPDATE OF ls,dc,p,s':''),[$session,$user,$this->org(),$user])->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$row['development_only'] || $row['paired_actor']!==$user) { throw new Failure(404,'SESSION_NOT_FOUND','Origin session not found.'); }
        return $row;
    }
    private function event(array $session,string $type): string {
        $evidence=['synthetic_simulation'=>true,'physical_hardware_verified'=>false,'event_type'=>$type,
            'session_id'=>(string)$session['id'],'compartment_id'=>(string)$session['compartment_id']];
        return (string)$this->q("INSERT INTO device_events(device_id,command_id,external_event_id,occurred_at,evidence) VALUES (?,?,?,now(),?::jsonb) RETURNING id",
            [$session['device_id'],$session['command_id'],Secrets::uuid(),json_encode($evidence,JSON_THROW_ON_ERROR)])->fetchColumn();
    }
    public function simulate(string $user,string $session,array $input,string $key): array {
        Shipping::id($session); Input::fields($input,['event']);
        if (!in_array($input['event'],['OPEN_OBSERVED','CLOSE_OBSERVED','UNKNOWN'],true)) { throw new Failure(422,'INVALID_EVENT','Choose a supported simulated door event.'); }
        return $this->once($user,'event:'.$session,$key,$input,function () use ($user,$session,$input) {
            $row=$this->session($user,$session);
            if ($row['order_status']!=='READY' || $row['payment_status']!=='PAID' || $row['package_state']!=='CREATED'
                || (int)$row['package_version']!==(int)$row['expected_package_version'] || $row['custodian_type']!=='SENDER' || $row['custodian_ref']!==$user) {
                throw new Failure(409,'PACKAGE_CHANGED','The parcel changed during this session.');
            }
            $type=$input['event'];
            if ($type==='CLOSE_OBSERVED') {
                if ($row['status']!=='OPEN' || $row['command_status']!=='OPEN_OBSERVED') { throw new Failure(409,'DOOR_SEQUENCE_INVALID','An observed open is required first.'); }
                $this->event($row,$type);
                $this->q("UPDATE locker_sessions SET status='CLOSED' WHERE id=?",[$session]);
            } else {
                if ($row['status']!=='READY' || $row['command_status']!=='PENDING' || strtotime($row['expires_at'])<=time()) {
                    throw new Failure(409,'DOOR_SEQUENCE_INVALID','This simulated command cannot be dispatched.');
                }
                if (!$this->q("SELECT 1 FROM package_labels WHERE package_id=? AND status='ACTIVE' AND expires_at>now() AND encode(token_hash,'hex')=?",[$row['package_id'],$row['credential_hex']])->fetchColumn()) {
                    throw new Failure(409,'LABEL_REVOKED','The primary label changed before door simulation.');
                }
                $this->event($row,'DISPATCH_RECORDED');
                $this->event($row,$type);
                $this->q('UPDATE locker_sessions SET status=? WHERE id=?',[$type==='UNKNOWN'?'UNKNOWN':'OPEN',$session]);
            }
            $this->q('UPDATE device_commands SET status=? WHERE id=?',[$type,$row['command_id']]);
            return ['session_id'=>$session,'status'=>$type==='CLOSE_OBSERVED'?'CLOSED':($type==='UNKNOWN'?'UNKNOWN':'OPEN'),
                'development_only'=>true,'physical_hardware_verified'=>false];
        });
    }
    public function confirm(string $user,string $session,array $input,string $key): array {
        Shipping::id($session); Input::fields($input,['placed']);
        if ($input['placed']!==true) { throw new Failure(422,'ATTESTATION_REQUIRED','Confirm that the parcel was placed in the virtual compartment.'); }
        return $this->once($user,'confirm:'.$session,$key,$input,function () use ($user,$session) {
            $row=$this->session($user,$session);
            if ($row['status']!=='CLOSED' || $row['command_status']!=='CLOSE_OBSERVED' || $row['order_status']!=='READY' || $row['payment_status']!=='PAID' || $row['package_state']!=='CREATED'
                || (int)$row['package_version']!==(int)$row['expected_package_version'] || $row['custodian_type']!=='SENDER' || $row['custodian_ref']!==$user) {
                throw new Failure(409,'DEPOSIT_NOT_CONFIRMED','Both door observations and sender custody are required.');
            }
            $label=$this->q("SELECT 1 FROM package_labels WHERE package_id=? AND status='ACTIVE' AND expires_at>now() AND encode(token_hash,'hex')=?",[$row['package_id'],$row['credential_hex']])->fetchColumn();
            if (!$label) { throw new Failure(409,'LABEL_REVOKED','The primary label changed during deposit.'); }
            $events=$this->q("SELECT id,evidence->>'event_type' AS type FROM device_events WHERE command_id=? AND device_id=? AND evidence->>'synthetic_simulation'='true' ORDER BY id",[$row['command_id'],$row['device_id']])->fetchAll(PDO::FETCH_ASSOC);
            if (array_column($events,'type')!==['DISPATCH_RECORDED','OPEN_OBSERVED','CLOSE_OBSERVED']) {
                throw new Failure(409,'EVIDENCE_INCOMPLETE','Correlated simulated door evidence is incomplete.');
            }
            $claim=$this->q("UPDATE compartment_claims SET state='OCCUPIED',expires_at=NULL WHERE session_id=? AND package_id=? AND compartment_id=? AND state='HELD' RETURNING id",[$session,$row['package_id'],$row['compartment_id']])->fetchColumn();
            if (!$claim) { throw new Failure(409,'CLAIM_MISSING','The virtual compartment claim is unavailable.'); }
            $version=(int)$row['package_version']+1;
            $this->q("UPDATE packages SET state='AT_ORIGIN',custodian_type='LOCKER',custodian_ref=?,current_location_id=?,version=? WHERE id=?",[$row['locker_id'],$row['location_id'],$version,$row['package_id']]);
            $this->q("UPDATE locker_sessions SET status='CONFIRMED',actor_attested_at=now(),version=version+1 WHERE id=?",[$session]);
            $evidence=['synthetic_simulation'=>true,'physical_hardware_verified'=>false,'locker_session_id'=>$session,
                'device_event_ids'=>array_map('strval',array_column($events,'id')),'actor_attested'=>true];
            $operation=Secrets::uuid();
            $this->q("INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at)
                VALUES (?,?,?,?,'ORIGIN_DEPOSIT_SIMULATED','SENDER',?,'LOCKER',?,?,?::jsonb,now())",
                [$row['package_id'],$operation,$version,$user,$user,$row['locker_id'],$row['location_id'],json_encode($evidence,JSON_THROW_ON_ERROR)]);
            $scan=(string)$this->q("INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,action,result_code,received_at) VALUES (?,?,?,'ORIGIN_DEPOSIT','SIMULATED_CONFIRMED',now()) RETURNING id",[Secrets::uuid(),$row['package_id'],$user])->fetchColumn();
            $this->q("INSERT INTO scan_evidence(scan_event_id,locker_session_id,device_event_id,assurance_level,actor_attestation) VALUES (?,?,?,'SIMULATED_DOOR_PLUS_ACTOR',?::jsonb)",
                [$scan,$session,$events[2]['id'],json_encode(['placed'=>true,'synthetic_simulation'=>true],JSON_THROW_ON_ERROR)]);
            $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,'ORIGIN_DEPOSIT_SIMULATED',?,?::jsonb,now())",
                [$row['package_id'],Secrets::uuid(),$user,json_encode($evidence,JSON_THROW_ON_ERROR)]);
            $demand=(string)$this->q("INSERT INTO pickup_demands(package_id,origin_location_id,status) VALUES (?,?,'OPEN') RETURNING id",[$row['package_id'],$row['location_id']])->fetchColumn();
            (new Outbox($this->db))->append(Secrets::uuid(),'package',(string)$row['package_id'],'origin.deposit_simulated',
                ['package_id'=>(string)$row['package_id'],'pickup_demand_id'=>$demand,'synthetic_simulation'=>true]);
            return ['session_id'=>$session,'package_id'=>(string)$row['package_id'],'package_state'=>'AT_ORIGIN','package_version'=>$version,
                'pickup_demand_id'=>$demand,'development_only'=>true,'physical_hardware_verified'=>false];
        });
    }
}
