<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use think\Request;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;

/** Short-lived, actor-bound Delivery terminal/app pairing for parcel workflows. */
final class Pairings
{
    private const CREATE_PATH='/api/delivery/v1/devices/me/pairings';
    public function __construct(private PDO $db,private Secrets $crypto) {}
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql);$q->execute($args);return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function response(array $row): array {
        $site=$this->q('SELECT tp.workflow,l.id,l.name FROM terminal_pairing_sessions tp JOIN locker_devices d ON d.id=tp.device_id JOIN lockers k ON k.id=d.locker_id JOIN locations l ON l.id=k.location_id WHERE tp.id=?',[$row['id']])->fetch(PDO::FETCH_ASSOC);
        return ['pairing_id'=>(string)$row['id'],'scene_payload'=>'ZPXPAIR:'.$row['id'].':'.$row['scene_uuid'],
            'status'=>$row['status'],'expires_at'=>gmdate('c',strtotime($row['expires_at'])),
            'workflow'=>$site['workflow'],'location_id'=>(string)$site['id'],'location_name'=>$site['name']];
    }
    public function inspect(string $user,string $pairingId,array $input): array {
        Input::fields($input,['scene_payload']);$id=\Zpx\Shipping\Service::id($pairingId);$scene=Input::text($input['scene_payload'],40,120);
        $row=$this->q("SELECT tp.id,tp.scene_uuid,tp.status,tp.expires_at,tp.workflow,tp.actor_user_id FROM terminal_pairing_sessions tp
            JOIN locker_devices d ON d.id=tp.device_id AND d.status='ACTIVE' JOIN lockers k ON k.id=d.locker_id JOIN locations l ON l.id=k.location_id
            WHERE tp.id=? AND l.organization_id=? AND l.site_mode='DELIVERY_ONLY' AND l.status='ACTIVE'
              AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)",[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['actor_user_id']!==null && $row['actor_user_id']!==$user) || !hash_equals('ZPXPAIR:'.$row['id'].':'.$row['scene_uuid'],$scene)) { throw new Failure(404,'PAIRING_NOT_FOUND','Scanned scene unavailable.'); }
        (new Identity($this->db,$this->crypto))->requireRole($user,in_array($row['workflow'],['FINAL_DEPOSIT','INBOUND_PICKUP'],true)?'DRIVER':'CUSTOMER');
        $result=$this->response($row);
        $session=$this->q('SELECT id FROM locker_sessions WHERE pairing_id=? AND actor_user_id=? ORDER BY id DESC LIMIT 1',[$id,$user])->fetchColumn();
        $result['session_id']=$session?(string)$session:null;
        return $result;
    }
    public function create(Request $request,array $input): array
    {
        Input::fields($input,['workflow']);
        if (!in_array($input['workflow'],['FINAL_DEPOSIT',...PhysicalSessions::ACTIONS],true)) { throw new Failure(409,'PAIRING_WORKFLOW_UNAVAILABLE','This Delivery pairing workflow is not implemented.'); }
        $key=Input::text($request->header('idempotency-key',''),16,100);
        return (new Transaction($this->db))->run(function () use ($request,$input,$key) {
            $device=(new DeviceCommands($this->db))->authenticate($request,self::CREATE_PATH,'POST');
            if (!$this->q("SELECT 1 FROM lockers k JOIN locations l ON l.id=k.location_id
                WHERE k.id=? AND l.organization_id=? AND l.status='ACTIVE' AND l.site_mode='DELIVERY_ONLY'
                  AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)",
                [$device['locker_id'],$this->org()])->fetchColumn()) {
                throw new Failure(409,'PAIRING_SITE_UNAVAILABLE','Pairing is unavailable at this site.');
            }
            $scope='device-pairing:'.$this->org().':'.$device['id'];
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('device-pairing',json_encode($input,JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','Pairing key was used for another workflow.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $row=$this->q("INSERT INTO terminal_pairing_sessions(scene_uuid,device_id,workflow,status,expires_at)
                VALUES (?,?,?,'PENDING',now()+interval '10 minutes') RETURNING id,scene_uuid,status,expires_at",
                [Secrets::uuid(),$device['id'],$input['workflow']])->fetch(PDO::FETCH_ASSOC);
            $result=$this->response($row);
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),201,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
    public function poll(Request $request,string $pairingId): array
    {
        $id=\Zpx\Shipping\Service::id($pairingId);
        $path='/api/delivery/v1/devices/me/pairings/'.$id;
        return (new Transaction($this->db))->run(function () use ($request,$id,$path) {
            $device=(new DeviceCommands($this->db))->authenticate($request,$path);
            $row=$this->q('SELECT id,scene_uuid,status,expires_at FROM terminal_pairing_sessions WHERE id=? AND device_id=? FOR UPDATE',
                [$id,$device['id']])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'PAIRING_NOT_FOUND','Pairing scene not found.'); }
            if ($row['status']==='PENDING' && strtotime($row['expires_at'])<=time()) {
                $this->q("UPDATE terminal_pairing_sessions SET status='EXPIRED' WHERE id=?",[$id]);
                $row['status']='EXPIRED';
            }
            $result=$this->response($row);
            $session=$this->q('SELECT id FROM locker_sessions WHERE pairing_id=? AND evidence_policy=\'ENROLLED_DOOR_PLUS_ACTOR\' ORDER BY id DESC LIMIT 1',[$id])->fetchColumn();
            $result['session_id']=$session?(string)$session:null;
            return $result;
        });
    }
    public function approve(string $user,string $pairingId,array $input,string $key): array
    {
        $id=\Zpx\Shipping\Service::id($pairingId);
        Input::fields($input,['workflow','location_id','scene_payload']);
        $location=\Zpx\Shipping\Service::id($input['location_id']);
        $scene=Input::text($input['scene_payload'],40,120);
        Input::text($key,16,100);
        if (!in_array($input['workflow'],['FINAL_DEPOSIT',...PhysicalSessions::ACTIONS],true)) { throw new Failure(409,'PAIRING_WORKFLOW_UNAVAILABLE','This Delivery pairing workflow is not implemented.'); }
        $identity=new Identity($this->db,$this->crypto);
        $identity->requireRole($user,in_array($input['workflow'],['FINAL_DEPOSIT','INBOUND_PICKUP'],true)?'DRIVER':'CUSTOMER');
        if ($input['workflow']!=='FINAL_DEPOSIT') { $identity->requireVerified($user); }
        return (new Transaction($this->db))->run(function () use ($user,$id,$input,$location,$scene,$key) {
            $scope='pairing-approval:'.$this->org().':'.$user.':'.$id;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('pairing-approval',json_encode($input,JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','Pairing key was used for other details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $row=$this->q("SELECT tp.id,tp.scene_uuid,tp.workflow,tp.status,tp.expires_at,l.id AS location_id
                FROM terminal_pairing_sessions tp JOIN locker_devices d ON d.id=tp.device_id AND d.status='ACTIVE'
                JOIN lockers k ON k.id=d.locker_id JOIN locations l ON l.id=k.location_id
                WHERE tp.id=? AND l.organization_id=? AND l.status='ACTIVE' AND l.site_mode='DELIVERY_ONLY'
                  AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)
                FOR UPDATE OF tp",[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['location_id']!==$location || $row['workflow']!==$input['workflow']
                || !hash_equals('ZPXPAIR:'.$row['id'].':'.$row['scene_uuid'],$scene)) {
                throw new Failure(404,'PAIRING_NOT_FOUND','Pairing scene does not match this site and workflow.');
            }
            if ($row['status']!=='PENDING' || strtotime($row['expires_at'])<=time()) {
                throw new Failure(409,'PAIRING_UNAVAILABLE','Pairing scene is no longer awaiting approval.');
            }
            $this->q("UPDATE terminal_pairing_sessions SET status='APPROVED',actor_user_id=? WHERE id=?",[$user,$id]);
            $row['status']='APPROVED';
            $result=$this->response($row);
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
