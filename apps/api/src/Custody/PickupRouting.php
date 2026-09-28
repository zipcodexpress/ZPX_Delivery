<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;

/** Prospective origin-to-hub policy. Assigned run manifests keep their original hub. */
final class PickupRouting
{
    private Identity $identity;
    public function __construct(private PDO $db,private Secrets $crypto) {
        $this->identity=new Identity($db,$crypto);
    }
    private function q(string $sql,array $args=[]): \PDOStatement {
        $q=$this->db->prepare($sql); $q->execute($args); return $q;
    }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }

    public static function hubForOrigin(PDO $db,string $org,string $origin): ?string {
        $q=$db->prepare("SELECT id FROM locations WHERE id=? AND organization_id=? AND kind='LOCKER' AND status='ACTIVE' FOR SHARE");
        $q->execute([$origin,$org]);
        if (!$q->fetchColumn()) { return null; }
        $q=$db->prepare("SELECT r.hub_id,h.status,hl.status AS location_status,hl.organization_id
            FROM origin_hub_routes r JOIN hubs h ON h.id=r.hub_id JOIN locations hl ON hl.id=h.location_id
            WHERE r.origin_location_id=?");
        $q->execute([$origin]);
        $route=$q->fetch(PDO::FETCH_ASSOC);
        if ($route) {
            return $route['status']==='ACTIVE' && $route['location_status']==='ACTIVE' && (string)$route['organization_id']===$org
                ? (string)$route['hub_id'] : null;
        }
        $q=$db->prepare("SELECT h.id FROM hubs h JOIN locations l ON l.id=h.location_id
            WHERE l.organization_id=? AND h.status='ACTIVE' AND l.status='ACTIVE' LIMIT 2");
        $q->execute([$org]); $hubs=$q->fetchAll(PDO::FETCH_COLUMN);
        return count($hubs)===1 ? (string)$hubs[0] : null;
    }

    public function list(string $user): array {
        $this->identity->requireRole($user,'ADMIN');
        $origins=$this->q("SELECT l.id,l.code,l.name,l.status,l.latitude,l.longitude,r.hub_id,r.version,h.name AS hub_name
            FROM locations l LEFT JOIN origin_hub_routes r ON r.origin_location_id=l.id
            LEFT JOIN hubs hb ON hb.id=r.hub_id LEFT JOIN locations h ON h.id=hb.location_id
            WHERE l.organization_id=? AND l.kind='LOCKER' ORDER BY l.code",[$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        $hubs=$this->q("SELECT hb.id,l.name,l.code FROM hubs hb JOIN locations l ON l.id=hb.location_id
            WHERE l.organization_id=? AND hb.status='ACTIVE' AND l.status='ACTIVE' ORDER BY l.code",[$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        return [
            'origins'=>array_map(static fn($r)=>['origin_location_id'=>(string)$r['id'],'code'=>$r['code'],'name'=>$r['name'],
                'status'=>$r['status'],'hub_id'=>$r['hub_id']===null?null:(string)$r['hub_id'],
                'hub_name'=>$r['hub_name'],'latitude'=>$r['latitude']===null?null:(float)$r['latitude'],
                'longitude'=>$r['longitude']===null?null:(float)$r['longitude'],
                'version'=>(int)($r['version']??0)],$origins),
            'hubs'=>array_map(static fn($r)=>['hub_id'=>(string)$r['id'],'code'=>$r['code'],'name'=>$r['name']],$hubs)
        ];
    }

    public function assign(string $user,array $input,string $key): array {
        $this->identity->requireRole($user,'ADMIN');
        Input::fields($input,['origin_location_id','hub_id','expected_version'],['latitude','longitude']);
        $origin=Input::text($input['origin_location_id'],1,18);
        $hub=Input::text($input['hub_id'],1,18);
        $version=filter_var($input['expected_version'],FILTER_VALIDATE_INT);
        if ($version===false || $version<0) { throw new Failure(422,'INVALID_INPUT','Route version must be nonnegative.'); }
        $hasLatitude=array_key_exists('latitude',$input); $hasLongitude=array_key_exists('longitude',$input);
        if ($hasLatitude!==$hasLongitude) { throw new Failure(422,'INVALID_INPUT','Provide both origin coordinates.'); }
        $latitude=null; $longitude=null;
        if ($hasLatitude) {
            if (!is_numeric($input['latitude']) || !is_numeric($input['longitude'])) { throw new Failure(422,'INVALID_INPUT','Coordinates must be numeric.'); }
            $latitude=(float)$input['latitude']; $longitude=(float)$input['longitude'];
            if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude)>90 || abs($longitude)>180) {
                throw new Failure(422,'INVALID_INPUT','Coordinates are outside the supported range.');
            }
        }
        Input::text($key,16,100);
        return (new Transaction($this->db))->run(function () use ($user,$origin,$hub,$version,$key,$latitude,$longitude,$hasLatitude) {
            $scope='pickup-route:'.$this->org().':'.$user;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('pickup-route',json_encode([$origin,$hub,$version,$latitude,$longitude],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was used for another route.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $site=$this->q("SELECT id FROM locations WHERE id=? AND organization_id=? AND kind='LOCKER' AND status='ACTIVE' FOR UPDATE",[$origin,$this->org()])->fetchColumn();
            $validHub=$this->q("SELECT h.id FROM hubs h JOIN locations l ON l.id=h.location_id
                WHERE h.id=? AND l.organization_id=? AND h.status='ACTIVE' AND l.status='ACTIVE'",[$hub,$this->org()])->fetchColumn();
            if (!$site || !$validHub) { throw new Failure(404,'ROUTE_ENDPOINT_NOT_FOUND','Active origin and hub must belong to this organization.'); }
            $current=$this->q("SELECT version FROM origin_hub_routes WHERE origin_location_id=? FOR UPDATE",[$origin])->fetchColumn();
            if ($version!==($current===false?0:(int)$current)) { throw new Failure(409,'ROUTE_VERSION_CONFLICT','Route changed. Refresh before saving.'); }
            if ($current===false) {
                $this->q("INSERT INTO origin_hub_routes(origin_location_id,hub_id,version,updated_by) VALUES (?,?,1,?)",[$origin,$hub,$user]);
                $newVersion=1;
            } else {
                $this->q("UPDATE origin_hub_routes SET hub_id=?,version=version+1,updated_by=?,updated_at=now() WHERE origin_location_id=?",[$hub,$user,$origin]);
                $newVersion=$version+1;
            }
            if ($hasLatitude) { $this->q('UPDATE locations SET latitude=?,longitude=? WHERE id=?',[$latitude,$longitude,$origin]); }
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'PICKUP_ROUTE_ASSIGNED','location',?)",[$user,$origin]);
            $result=['origin_location_id'=>$origin,'hub_id'=>$hub,'version'=>$newVersion];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
