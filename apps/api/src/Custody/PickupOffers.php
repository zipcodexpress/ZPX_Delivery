<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;

/** Driver-requested inbound work; acceptance freezes exact packages into a run. */
final class PickupOffers
{
    private Identity $identity;
    public function __construct(private PDO $db, private Secrets $crypto) {
        $this->identity = new Identity($db, $crypto);
    }
    private function q(string $sql, array $args=[]): \PDOStatement {
        $q=$this->db->prepare($sql); $q->execute($args); return $q;
    }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function developmentAllowed(): string { return in_array(getenv('APP_ENV'),['development','test'],true) ? 'true' : 'false'; }
    private function driver(string $user): array {
        $this->identity->requireRole($user,'DRIVER');
        $row=$this->q("SELECT d.id,d.status FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.user_id=? AND u.organization_id=? AND u.status='ACTIVE'",[$user,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status']!=='ACTIVE') { throw new Failure(403,'DRIVER_UNAVAILABLE','An approved active driver is required.'); }
        return $row;
    }
    private function shift(string $driver): array {
        $row=$this->q("SELECT ds.vehicle_id,v.max_packages,v.max_weight_g,v.max_volume_mm3,ds.ends_at
            FROM driver_shifts ds JOIN vehicles v ON v.id=ds.vehicle_id
            WHERE ds.driver_id=? AND v.organization_id=? AND ds.starts_at<=now() AND ds.ends_at>now()
            ORDER BY ds.ends_at LIMIT 1",[$driver,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(409,'NO_ACTIVE_SHIFT','Start an assigned shift before requesting pickups.'); }
        return $row;
    }
    public function availability(string $user,array $input,string $key=''): array {
        Input::fields($input,['status'],['latitude','longitude']);
        if (!in_array($input['status'],['AVAILABLE','OFFLINE'],true)) { throw new Failure(422,'INVALID_INPUT','Choose AVAILABLE or OFFLINE.'); }
        $hasLatitude=array_key_exists('latitude',$input);
        $hasLongitude=array_key_exists('longitude',$input);
        if ($hasLatitude!==$hasLongitude || ($input['status']==='OFFLINE' && $hasLatitude)) {
            throw new Failure(422,'INVALID_INPUT','Provide both coordinates only while available.');
        }
        $latitude=null; $longitude=null;
        if ($hasLatitude) {
            if (!is_numeric($input['latitude']) || !is_numeric($input['longitude'])) { throw new Failure(422,'INVALID_INPUT','Coordinates must be numeric.'); }
            $latitude=(float)$input['latitude']; $longitude=(float)$input['longitude'];
            if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude)>90 || abs($longitude)>180) {
                throw new Failure(422,'INVALID_INPUT','Coordinates are outside the supported range.');
            }
        }
        $driver=$this->driver($user);
        return (new Transaction($this->db))->run(function () use ($user,$driver,$input,$latitude,$longitude,$key) {
            $currentDriver=$this->q('SELECT status FROM drivers WHERE id=? FOR UPDATE',[$driver['id']])->fetchColumn();
            if ($currentDriver!=='ACTIVE') { throw new Failure(403,'DRIVER_UNAVAILABLE','An approved active driver is required.'); }
            $scope='pickup-availability:'.$this->org().':'.$user;
            $hash=$this->crypto->digest('pickup-availability',json_encode([$input['status'],$latitude,$longitude],JSON_THROW_ON_ERROR));
            if ($key!=='') {
                $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
                if ($saved) {
                    if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was used for different availability.'); }
                    return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
                }
            }
            if ($input['status']==='AVAILABLE') { $this->shift((string)$driver['id']); }
            $this->q("INSERT INTO driver_availability(driver_id,status,latitude,longitude,updated_at,location_updated_at)
                VALUES (?,?,?,?,now(),CASE WHEN ?::boolean THEN now() ELSE NULL END)
                ON CONFLICT(driver_id) DO UPDATE SET status=EXCLUDED.status,latitude=EXCLUDED.latitude,
                longitude=EXCLUDED.longitude,updated_at=now(),location_updated_at=EXCLUDED.location_updated_at",
                [$driver['id'],$input['status'],$latitude,$longitude,$latitude!==null?'true':'false']);
            if ($input['status']==='OFFLINE') {
                $this->q("UPDATE driver_offers SET status='CANCELLED' WHERE driver_id=? AND status='OFFERED'",[$driver['id']]);
            }
            $result=['status'=>$input['status'],'location_shared'=>$latitude!==null];
            if ($key!=='') { $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),200,?,now()+interval '1 day')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]); }
            return $result;
        });
    }
    private function available(string $driver): void {
        if (!$this->q("SELECT 1 FROM driver_availability WHERE driver_id=? AND status='AVAILABLE' AND updated_at>now()-interval '4 hours'",[$driver])->fetchColumn()) {
            throw new Failure(409,'DRIVER_OFFLINE','Mark yourself available before requesting or accepting pickups.');
        }
    }
    private function position(string $driver): ?array {
        $row=$this->q('SELECT latitude,longitude,location_updated_at FROM driver_availability WHERE driver_id=?',[$driver])->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['latitude']!==null && $row['longitude']!==null && $row['location_updated_at']!==null
            && strtotime($row['location_updated_at'])>time()-900) { return $row; }
        if ($this->developmentAllowed()==='true') { return null; }
        throw new Failure(409,'LOCATION_REQUIRED','Share your current location to find nearby pickups.');
    }
    private function withinRadius(?array $position,array $origin): bool {
        if ($position===null) { return true; } // Local development supports fixtures without coordinates.
        if ($origin['latitude']===null || $origin['longitude']===null) { return $this->developmentAllowed()==='true'; }
        $lat1=deg2rad((float)$position['latitude']); $lat2=deg2rad((float)$origin['latitude']);
        $deltaLat=$lat2-$lat1; $deltaLon=deg2rad((float)$origin['longitude']-(float)$position['longitude']);
        $a=sin($deltaLat/2)**2+cos($lat1)*cos($lat2)*sin($deltaLon/2)**2;
        return 12742*asin(min(1,sqrt($a)))<=25; // 25 km service radius; no route optimization.
    }
    public function refresh(string $user,string $key=''): array {
        $driver=$this->driver($user);
        return (new Transaction($this->db))->run(function () use ($driver,$user,$key) {
            $currentDriver=$this->q('SELECT status FROM drivers WHERE id=? FOR UPDATE',[$driver['id']])->fetchColumn();
            if ($currentDriver!=='ACTIVE') { throw new Failure(403,'DRIVER_UNAVAILABLE','An approved active driver is required.'); }
            $scope='pickup-refresh:'.$this->org().':'.$user;
            $hash=$this->crypto->digest('pickup-refresh','current');
            if ($key!=='') {
                $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
                if ($saved) {
                    if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was used for another pickup request.'); }
                    return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
                }
            }
            $this->shift((string)$driver['id']);
            $this->available((string)$driver['id']);
            $position=$this->position((string)$driver['id']);
            $this->q("UPDATE driver_offers SET status='CANCELLED' WHERE driver_id=? AND status='OFFERED'",[$driver['id']]);
            $origins=$this->q("SELECT pd.origin_location_id,l.latitude,l.longitude,MIN(pd.pickup_deadline) AS deadline
                FROM pickup_demands pd JOIN packages p ON p.id=pd.package_id
                JOIN shipments s ON s.id=p.shipment_id JOIN locations l ON l.id=pd.origin_location_id
                WHERE s.organization_id=? AND pd.status='OPEN' AND pd.pickup_deadline>now()
                AND p.state='AT_ORIGIN' AND p.custodian_type='LOCKER' AND p.current_location_id=pd.origin_location_id
                AND s.payment_status='PAID' AND s.order_status='READY' AND (NOT s.development_only OR ?::boolean) AND l.status='ACTIVE'
                AND NOT EXISTS(SELECT 1 FROM active_allocations a WHERE a.package_id=p.id)
                GROUP BY pd.origin_location_id,l.latitude,l.longitude",[$this->org(),$this->developmentAllowed()])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($origins as $origin) {
                if (!$this->withinRadius($position,$origin)) { continue; }
                $hub=PickupRouting::hubForOrigin($this->db,$this->org(),(string)$origin['origin_location_id']);
                if ($hub===null) { continue; }
                $offerId=(string)$this->q("INSERT INTO driver_offers(driver_id,origin_location_id,hub_id,status,expires_at)
                    VALUES (?,?,?,'OFFERED',LEAST(now()+interval '15 minutes',?::timestamptz)) RETURNING id",
                    [$driver['id'],$origin['origin_location_id'],$hub,$origin['deadline']])->fetchColumn();
                $this->q("INSERT INTO driver_offer_items(offer_id,demand_id,demand_version)
                    SELECT ?,pd.id,pd.version FROM pickup_demands pd JOIN packages p ON p.id=pd.package_id
                    JOIN shipments s ON s.id=p.shipment_id
                    WHERE pd.origin_location_id=? AND pd.status='OPEN' AND pd.pickup_deadline>now()
                    AND s.organization_id=? AND s.payment_status='PAID' AND s.order_status='READY' AND (NOT s.development_only OR ?::boolean)
                    AND p.state='AT_ORIGIN' AND p.custodian_type='LOCKER' AND p.current_location_id=pd.origin_location_id
                    AND NOT EXISTS(SELECT 1 FROM active_allocations a WHERE a.package_id=p.id)",
                    [$offerId,$origin['origin_location_id'],$this->org(),$this->developmentAllowed()]);
                if (!$this->q('SELECT 1 FROM driver_offer_items WHERE offer_id=?',[$offerId])->fetchColumn()) {
                    $this->q("UPDATE driver_offers SET status='CANCELLED' WHERE id=?",[$offerId]);
                }
            }
            $result=$this->list($user);
            if ($key!=='') { $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),200,?,now()+interval '1 day')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]); }
            return $result;
        });
    }
    public function list(string $user): array {
        $driver=$this->driver($user);
        $rows=$this->q("SELECT o.id,o.expires_at,l.name AS origin,l.address_text,h.id AS hub_id,hl.name AS hub,
                COUNT(pd.id) AS package_count
            FROM driver_offers o JOIN locations l ON l.id=o.origin_location_id
            JOIN hubs h ON h.id=o.hub_id JOIN locations hl ON hl.id=h.location_id
            JOIN driver_offer_items oi ON oi.offer_id=o.id
            JOIN pickup_demands pd ON pd.id=oi.demand_id
            JOIN packages p ON p.id=pd.package_id
            JOIN shipments s ON s.id=p.shipment_id AND s.organization_id=?
            WHERE o.driver_id=? AND o.status='OFFERED' AND o.expires_at>now()
            GROUP BY o.id,l.name,l.address_text,h.id,hl.name
            HAVING COUNT(*)=COUNT(*) FILTER (WHERE pd.status='OPEN' AND pd.version=oi.demand_version
              AND pd.pickup_deadline>now() AND (NOT s.development_only OR ?::boolean)
              AND p.state='AT_ORIGIN' AND p.custodian_type='LOCKER'
              AND p.current_location_id=o.origin_location_id AND s.payment_status='PAID' AND s.order_status='READY'
              AND NOT EXISTS(SELECT 1 FROM active_allocations a WHERE a.package_id=p.id))
            ORDER BY o.expires_at,o.id",
            [$this->org(),$driver['id'],$this->developmentAllowed()])->fetchAll(PDO::FETCH_ASSOC);
        return ['items'=>array_map(static fn($r)=>[
            'offer_id'=>(string)$r['id'],'origin'=>$r['origin'],'address'=>$r['address_text'],
            'hub'=>$r['hub'],'package_count'=>(int)$r['package_count'],'expires_at'=>$r['expires_at']
        ],$rows)];
    }
    public function accept(string $user,string $offerId,string $key): array {
        Input::text($offerId,1,18); Input::text($key,16,100);
        $driver=$this->driver($user);
        return (new Transaction($this->db))->run(function () use ($user,$driver,$offerId,$key) {
            $scope='pickup-offer:'.$this->org().':'.$user;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('pickup-offer-accept',$offerId);
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was used for another offer.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $currentDriver=$this->q('SELECT status FROM drivers WHERE id=? FOR UPDATE',[$driver['id']])->fetchColumn();
            if ($currentDriver!=='ACTIVE') { throw new Failure(403,'DRIVER_UNAVAILABLE','An approved active driver is required.'); }
            $offer=$this->q("SELECT o.*,l.latitude,l.longitude FROM driver_offers o JOIN locations l ON l.id=o.origin_location_id
                JOIN hubs h ON h.id=o.hub_id JOIN locations hl ON hl.id=h.location_id
                WHERE o.id=? AND o.driver_id=? AND l.organization_id=? AND hl.organization_id=? FOR UPDATE OF o",
                [$offerId,$driver['id'],$this->org(),$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$offer) { throw new Failure(404,'OFFER_NOT_FOUND','Pickup offer not found.'); }
            $shift=$this->shift((string)$driver['id']);
            $this->available((string)$driver['id']);
            if ($offer['status']!=='OFFERED' || strtotime($offer['expires_at'])<=time()) { throw new Failure(409,'OFFER_EXPIRED','This offer is no longer available.'); }
            $hub=PickupRouting::hubForOrigin($this->db,$this->org(),(string)$offer['origin_location_id']);
            if ($hub===null || $hub!==(string)$offer['hub_id']) { throw new Failure(409,'PICKUP_ROUTE_CHANGED','The origin hub changed. Request a fresh offer.'); }
            if (!$this->withinRadius($this->position((string)$driver['id']),$offer)) {
                throw new Failure(409,'OUTSIDE_PICKUP_AREA','The origin is outside your current pickup area.');
            }
            $demands=$this->q("SELECT pd.id,pd.package_id,p.weight_g,(p.width_mm::bigint*p.height_mm*p.depth_mm) AS volume_mm3,
                pd.status,pd.version,oi.demand_version,pd.pickup_deadline,p.state AS package_state,p.custodian_type,p.current_location_id,
                s.payment_status,s.order_status,s.development_only,a.id AS allocation_id
                FROM driver_offer_items oi JOIN pickup_demands pd ON pd.id=oi.demand_id
                JOIN packages p ON p.id=pd.package_id
                JOIN shipments s ON s.id=p.shipment_id
                LEFT JOIN active_allocations a ON a.package_id=p.id
                WHERE oi.offer_id=? AND pd.origin_location_id=? AND s.organization_id=?
                ORDER BY pd.id FOR UPDATE OF pd,p",[$offerId,$offer['origin_location_id'],$this->org()])->fetchAll(PDO::FETCH_ASSOC);
            if (!$demands) { throw new Failure(409,'PICKUP_TAKEN','These parcels are no longer available.'); }
            foreach ($demands as $demand) {
                if ($demand['status']!=='OPEN' || (int)$demand['version']!==(int)$demand['demand_version']
                    || strtotime($demand['pickup_deadline'])<=time() || $demand['package_state']!=='AT_ORIGIN'
                    || $demand['custodian_type']!=='LOCKER' || (string)$demand['current_location_id']!==(string)$offer['origin_location_id']
                    || $demand['payment_status']!=='PAID' || $demand['order_status']!=='READY' || $demand['allocation_id']!==null
                    || (!in_array(getenv('APP_ENV'),['development','test'],true) && in_array($demand['development_only'],[true,'t','1',1],true))) {
                    throw new Failure(409,'PICKUP_TAKEN','The offered parcel set changed. Request a fresh offer.');
                }
            }
            $run=$this->q("SELECT r.id,r.revision,m.id AS manifest_id
                FROM inbound_offer_runs ir JOIN route_runs r ON r.id=ir.run_id
                JOIN manifests m ON m.run_id=r.id AND m.state='ACTIVE'
                WHERE r.driver_id=? AND r.hub_id=? AND r.vehicle_id=? AND r.state='PUBLISHED'
                  AND r.created_at>now()-interval '1 hour' AND r.planned_end>now()
                ORDER BY r.id DESC LIMIT 1 FOR UPDATE OF r,m",
                [$driver['id'],$offer['hub_id'],$shift['vehicle_id']])->fetch(PDO::FETCH_ASSOC);
            $existing=$run ? $this->q("SELECT COUNT(*) AS packages,COALESCE(SUM(p.weight_g),0) AS weight_g,
                COALESCE(SUM(p.width_mm::bigint*p.height_mm*p.depth_mm),0) AS volume_mm3
                FROM manifest_items mi JOIN packages p ON p.id=mi.package_id WHERE mi.run_id=?",[$run['id']])->fetch(PDO::FETCH_ASSOC) : ['packages'=>0,'weight_g'=>0,'volume_mm3'=>0];
            $count=(int)$existing['packages']+count($demands);
            $weight=(int)$existing['weight_g']+array_sum(array_column($demands,'weight_g'));
            $volume=(int)$existing['volume_mm3']+array_sum(array_column($demands,'volume_mm3'));
            if ($count>(int)$shift['max_packages'] || $weight>(int)$shift['max_weight_g'] || $volume>(int)$shift['max_volume_mm3']) {
                throw new Failure(409,'VEHICLE_CAPACITY','The offered parcels exceed your assigned vehicle capacity.');
            }
            if ($run) {
                if ($this->q("SELECT 1 FROM route_run_stops WHERE run_id=? AND location_id=?",[$run['id'],$offer['origin_location_id']])->fetchColumn()) {
                    throw new Failure(409,'STOP_ALREADY_ASSIGNED','This origin is already on your run.');
                }
                $runId=(string)$run['id']; $manifest=(string)$run['manifest_id'];
                $sequence=(int)$this->q("SELECT COALESCE(MAX(sequence_no),0)+1 FROM route_run_stops WHERE run_id=?",[$runId])->fetchColumn();
                $this->q("UPDATE route_runs SET revision=revision+1 WHERE id=?",[$runId]);
                $this->q("UPDATE manifests SET revision=revision+1 WHERE id=?",[$manifest]);
            } else {
                $runId=(string)$this->q("INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,revision,planned_start,planned_end)
                    VALUES (?,?,?,?,'INBOUND','PUBLISHED',1,now(),LEAST(?::timestamptz,now()+interval '4 hours')) RETURNING id",
                    [$this->org(),$offer['hub_id'],$driver['id'],$shift['vehicle_id'],$shift['ends_at']])->fetchColumn();
                $this->q("INSERT INTO inbound_offer_runs(run_id) VALUES (?)",[$runId]);
                $manifest=(string)$this->q("INSERT INTO manifests(run_id,revision,state) VALUES (?,1,'ACTIVE') RETURNING id",[$runId])->fetchColumn();
                $sequence=1;
            }
            $stop=(string)$this->q("INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES (?,?,?,'EXPECTED') RETURNING id",
                [$runId,$offer['origin_location_id'],$sequence])->fetchColumn();
            foreach ($demands as $demand) {
                $item=(string)$this->q("INSERT INTO manifest_items(manifest_id,run_id,package_id,stop_id,state)
                    VALUES (?,?,?,?,'EXPECTED') RETURNING id",[$manifest,$runId,$demand['package_id'],$stop])->fetchColumn();
                $this->q("INSERT INTO active_allocations(package_id,manifest_item_id) VALUES (?,?)",[$demand['package_id'],$item]);
                $this->q("UPDATE pickup_demands SET status='ASSIGNED',assigned_run_id=?,version=version+1 WHERE id=?",[$runId,$demand['id']]);
            }
            $this->q("UPDATE driver_offers SET status='ACCEPTED',accepted_run_id=?,request_key=? WHERE id=?",[$runId,$key,$offerId]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'PICKUP_OFFER_ACCEPTED','run',?)",[$user,$runId]);
            $result=['run_id'=>$runId,'stop_id'=>$stop,'package_count'=>count($demands),'revision'=>$run ? (int)$run['revision']+1 : 1];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",
                [$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
