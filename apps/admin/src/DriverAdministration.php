<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure, Input, Secrets};
use Zpx\Infrastructure\Database\Transaction;

final class DriverAdministration
{
    public function __construct(private PDO $db, private Secrets $crypto) {}
    private function query(string $sql, array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function authorize(string $actor): void { (new Access($this->db))->requireNetworkAdmin($actor); }

    public function list(string $actor, string $cursor=''): array
    {
        $this->authorize($actor);
        if ($cursor!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)) { throw new Failure(422,'INVALID_CURSOR','Invalid driver cursor.'); }
        $args=[$this->org()]; $where='';
        if ($cursor!=='') { $where=' AND d.id<?'; $args[]=$cursor; }
        $rows=$this->query("SELECT d.id,d.user_id,d.status,d.engagement_type,d.applied_at,d.version,u.display_name,
            (SELECT count(*) FROM route_runs rr WHERE rr.driver_id=d.id AND rr.state IN ('PUBLISHED','ACKNOWLEDGED','IN_PROGRESS')) AS active_run_count
            FROM drivers d JOIN users u ON u.id=d.user_id WHERE u.organization_id=?".$where.' ORDER BY d.id DESC LIMIT 26',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        return ['items'=>array_map(static fn($row)=>[
            'driver_id'=>(string)$row['id'],'user_id'=>(string)$row['user_id'],'name'=>$row['display_name'],
            'status'=>$row['status'],'engagement_type'=>$row['engagement_type'],'applied_at'=>$row['applied_at'],
            'version'=>(int)$row['version'],'active_run_count'=>(int)$row['active_run_count'],
        ],$rows),'next_cursor'=>$more?(string)end($rows)['id']:null];
    }

    public function detail(string $actor, string $driver): array
    {
        $this->authorize($actor);
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$driver)) { throw new Failure(422,'INVALID_INPUT','Invalid driver ID.'); }
        $row=$this->query("SELECT d.id,d.user_id,d.status,d.engagement_type,d.applied_at,d.approved_at,d.version,
            d.verification_status,d.license_expiry,d.vehicle_make,d.vehicle_model,u.display_name,
            da.status AS availability_status,da.location_updated_at
            FROM drivers d JOIN users u ON u.id=d.user_id LEFT JOIN driver_availability da ON da.driver_id=d.id
            WHERE d.id=? AND u.organization_id=?",[$driver,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(404,'DRIVER_NOT_FOUND','Driver not found.'); }
        $runs=$this->query("SELECT r.id,r.kind,r.state,r.planned_start,r.planned_end,l.name AS hub_name,v.code AS vehicle_code
            FROM route_runs r JOIN hubs h ON h.id=r.hub_id JOIN locations l ON l.id=h.location_id
            JOIN vehicles v ON v.id=r.vehicle_id WHERE r.driver_id=? AND r.organization_id=?
            AND l.organization_id=? AND v.organization_id=?
            ORDER BY CASE WHEN r.state IN ('PUBLISHED','ACKNOWLEDGED','IN_PROGRESS') THEN 0 ELSE 1 END,r.id DESC LIMIT 10",
            [$driver,$this->org(),$this->org(),$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        $activity=$this->query("SELECT action,created_at FROM audit_events WHERE entity_type='driver' AND entity_id=? ORDER BY id DESC LIMIT 10",[$driver])->fetchAll(PDO::FETCH_ASSOC);
        return ['driver'=>[
            'driver_id'=>(string)$row['id'],'user_id'=>(string)$row['user_id'],'name'=>$row['display_name'],
            'status'=>$row['status'],'engagement_type'=>$row['engagement_type'],'applied_at'=>$row['applied_at'],
            'approved_at'=>$row['approved_at'],'version'=>(int)$row['version'],
            'verification_status'=>$row['verification_status'],'license_expiry'=>$row['license_expiry'],
            'vehicle_description'=>trim(implode(' ',array_filter([$row['vehicle_make'],$row['vehicle_model']]))),
            'availability_status'=>$row['availability_status']??'OFFLINE',
            'location_updated_at'=>$row['location_updated_at'],
        ],'runs'=>array_map(static fn($r)=>[
            'run_id'=>(string)$r['id'],'kind'=>$r['kind'],'state'=>$r['state'],
            'planned_start'=>$r['planned_start'],'planned_end'=>$r['planned_end'],
            'hub_name'=>$r['hub_name'],'vehicle_code'=>$r['vehicle_code'],
        ],$runs),'activity'=>$activity];
    }

    public function transition(string $actor, string $driver, array $input, string $key): array
    {
        $this->authorize($actor);
        Input::fields($input,['action','reason','expected_version']);
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$driver)) { throw new Failure(422,'INVALID_INPUT','Invalid driver ID.'); }
        $action=$input['action'];
        if (!in_array($action,['SUSPEND','REACTIVATE'],true)) { throw new Failure(422,'INVALID_INPUT','Choose SUSPEND or REACTIVATE.'); }
        $reason=Input::text(trim(Input::text($input['reason'],1,500)),10,500);
        $version=filter_var($input['expected_version'],FILTER_VALIDATE_INT);
        if ($version===false || $version<0) { throw new Failure(422,'INVALID_INPUT','Driver version is invalid.'); }
        Input::text($key,16,100);
        return (new Transaction($this->db))->run(function () use ($actor,$driver,$action,$reason,$version,$key) {
            $scope='driver-transition:'.$this->org().':'.$actor.':'.$driver;
            $this->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('driver-transition',json_encode([$driver,$action,$reason,$version],JSON_THROW_ON_ERROR));
            $saved=$this->query('SELECT encode(payload_hash,\'hex\') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?',[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','Request key was used with different details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $row=$this->query('SELECT d.id,d.status,d.version,u.status AS user_status FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=? AND u.organization_id=? FOR UPDATE OF d',[$driver,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'DRIVER_NOT_FOUND','Driver not found.'); }
            if ((int)$row['version']!==$version) { throw new Failure(412,'STALE_VERSION','Driver changed. Refresh before retrying.'); }
            $expected=$action==='SUSPEND'?'ACTIVE':'SUSPENDED';
            if ($row['status']!==$expected || ($action==='REACTIVATE' && $row['user_status']!=='ACTIVE')) {
                throw new Failure(409,'INVALID_STATUS','Driver cannot make this transition.');
            }
            $next=$action==='SUSPEND'?'SUSPENDED':'ACTIVE';
            $this->query('UPDATE drivers SET status=?,version=version+1 WHERE id=?',[$next,$driver]);
            if ($action==='SUSPEND') {
                $this->query("UPDATE driver_availability SET status='OFFLINE',latitude=NULL,longitude=NULL,location_updated_at=NULL,updated_at=now() WHERE driver_id=?",[$driver]);
                $this->query("UPDATE driver_offers SET status='CANCELLED' WHERE driver_id=? AND status='OFFERED'",[$driver]);
            }
            $this->query('INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,?,?,?,?)',
                [$actor,$action==='SUSPEND'?'DRIVER_SUSPENDED':'DRIVER_REACTIVATED','driver',$driver,$reason]);
            $result=['driver_id'=>$driver,'status'=>$next,'version'=>$version+1];
            $this->query("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",
                [$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
