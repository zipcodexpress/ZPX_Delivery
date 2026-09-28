<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use think\Request;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Shipping\Service as Shipping;

/** Terminal-created scene, explicitly approved by the driver at the arrived destination. */
final class DevicePairings
{
    private const PATH='/api/delivery/v1/devices/me/pairings';
    private Identity $identity;
    public function __construct(private PDO $db,private Secrets $crypto) { $this->identity=new Identity($db,$crypto); }
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }

    public function create(Request $request): array
    {
        if ($request->method(true)!=='POST') { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
        if (strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') { throw new Failure(415,'JSON_REQUIRED','Send a JSON request.'); }
        $raw=$request->getInput();
        if (strlen($raw)>4096) { throw new Failure(413,'REQUEST_TOO_LARGE','Request is too large.'); }
        try { $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new Failure(400,'INVALID_JSON','Request is not valid JSON.'); }
        if (!is_array($input) || array_is_list($input)) { throw new Failure(422,'INVALID_INPUT','Send a JSON object.'); }
        Input::fields($input,['workflow']);
        if ($input['workflow']!=='FINAL_DEPOSIT') { throw new Failure(422,'INVALID_WORKFLOW','Only final destination pairing is available.'); }
        return (new Transaction($this->db))->run(function () use ($request) {
            $device=(new DeviceAuth($this->db))->authenticate($request,'POST',self::PATH);
            $site=$this->q("SELECT l.id,l.name FROM lockers k JOIN locations l ON l.id=k.location_id WHERE k.id=? AND l.status='ACTIVE'",[$device['locker_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$site) { throw new Failure(409,'SITE_UNAVAILABLE','Destination site is unavailable.'); }
            $pending=(int)$this->q("SELECT count(*) FROM terminal_pairing_sessions WHERE device_id=? AND workflow='FINAL_DEPOSIT' AND status='PENDING' AND expires_at>now()",[$device['id']])->fetchColumn();
            if ($pending>=3) { throw new Failure(429,'PAIRING_LIMIT','Too many pending pairing scenes.'); }
            $scene=Secrets::uuid();
            $row=$this->q("INSERT INTO terminal_pairing_sessions(scene_uuid,device_id,workflow,status,expires_at)
                VALUES (?,?,'FINAL_DEPOSIT','PENDING',now()+interval '5 minutes') RETURNING id,expires_at",[$scene,$device['id']])->fetch(PDO::FETCH_ASSOC);
            return ['scene_uuid'=>$scene,'pairing_id'=>(string)$row['id'],'workflow'=>'FINAL_DEPOSIT','status'=>'PENDING',
                'location_id'=>(string)$site['id'],'location_name'=>$site['name'],'expires_at'=>$row['expires_at']];
        });
    }

    public function preview(string $user,string $scene,string $runId,string $stopId): array
    {
        $this->identity->requireRole($user,'DRIVER');
        $row=$this->eligible($user,$scene,$runId,$stopId,false);
        return ['scene_uuid'=>$scene,'workflow'=>'FINAL_DEPOSIT','status'=>$row['status'],'location_id'=>(string)$row['location_id'],
            'location_name'=>$row['location_name'],'expires_at'=>$row['expires_at']];
    }

    public function approve(string $user,string $scene,string $runId,string $stopId,array $input,string $key,string $match=''): array
    {
        $this->identity->requireRole($user,'DRIVER');
        Input::fields($input,['expected_revision']);
        Shipping::id($runId); Shipping::id($stopId); Input::text($key,16,100);
        if (!is_int($input['expected_revision']) || $input['expected_revision']<1) { throw new Failure(422,'INVALID_INPUT','Expected run revision must be positive.'); }
        if ($match!=='' && $match!=='"'.$input['expected_revision'].'"') { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision precondition does not match.'); }
        return (new Transaction($this->db))->run(function () use ($user,$scene,$runId,$stopId,$input,$key) {
            $scope='pairing-approval:'.$this->org().':'.$user;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('pairing-approval',json_encode([$scene,$runId,$stopId,$input['expected_revision']],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) { if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was already used for other details.'); } return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR); }
            $row=$this->eligible($user,$scene,$runId,$stopId,true);
            if ((int)$row['revision']!==$input['expected_revision']) { throw new Failure(409,'RUN_REVISION_CONFLICT','Run changed. Refresh before pairing.'); }
            if ($row['status']!=='PENDING' || $row['actor_user_id']!==null) { throw new Failure(409,'PAIRING_NOT_PENDING','Pairing scene is no longer pending.'); }
            $this->q("UPDATE terminal_pairing_sessions SET actor_user_id=?,status='APPROVED' WHERE id=?",[$user,$row['id']]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'DESTINATION_PAIRING_APPROVED','terminal_pairing_session',?)",[$user,$row['id']]);
            $result=['pairing_id'=>(string)$row['id'],'scene_uuid'=>$scene,'status'=>'APPROVED','workflow'=>'FINAL_DEPOSIT',
                'location_id'=>(string)$row['location_id'],'location_name'=>$row['location_name'],'expires_at'=>$row['expires_at']];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    private function eligible(string $user,string $scene,string $runId,string $stopId,bool $lock): array
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8a-f][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$scene)) { throw new Failure(404,'PAIRING_NOT_FOUND','Pairing scene not found.'); }
        Shipping::id($runId); Shipping::id($stopId);
        $row=$this->q("SELECT tp.id,tp.status,tp.expires_at,tp.actor_user_id,tp.device_id,k.id AS locker_id,
                l.id AS location_id,l.name AS location_name,r.revision,rs.state AS stop_state,r.state AS run_state
            FROM terminal_pairing_sessions tp JOIN locker_devices d ON d.id=tp.device_id AND d.status='ACTIVE'
            JOIN lockers k ON k.id=d.locker_id JOIN locations l ON l.id=k.location_id AND l.status='ACTIVE'
            JOIN route_run_stops rs ON rs.id=? AND rs.location_id=l.id
            JOIN route_runs r ON r.id=rs.run_id JOIN drivers dr ON dr.id=r.driver_id
            WHERE tp.scene_uuid=?::uuid AND tp.workflow='FINAL_DEPOSIT' AND tp.expires_at>now()
              AND r.id=? AND r.kind='OUTBOUND' AND r.organization_id=? AND r.state='IN_PROGRESS' AND r.departed_at IS NOT NULL
              AND dr.user_id=? AND rs.state='ARRIVED' AND k.capabilities->>'physical_commands_enabled'='true'
              AND k.capabilities->>'synthetic'='false'
              AND EXISTS (SELECT 1 FROM manifest_items mi JOIN packages p ON p.id=mi.package_id
                  WHERE mi.run_id=r.id AND mi.stop_id=rs.id AND mi.state='LOADED'
                    AND p.state='OUTBOUND_CUSTODY' AND p.custodian_type='DRIVER' AND p.custodian_ref=dr.id::text)
            ".($lock?'FOR UPDATE OF tp,r,rs':''),[$stopId,$scene,$runId,$this->org(),$user])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(404,'PAIRING_NOT_FOUND','Pairing scene is unavailable for this arrived stop.'); }
        return $row;
    }
}
