<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets};
use Zpx\Infrastructure\Database\Transaction;

/** Cabinet configuration; operational custody remains in compartments. */
final class LockerSetups
{
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function authorize(string $actor): void { (new Access($this->db))->requireNetworkAdmin($actor); }
    private static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,17}$/D',$value)) {
            throw new Failure(422,'INVALID_ID','Invalid ID.');
        }
        return $value;
    }
    private static function number(mixed $value,int $max): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int)$value<1 || (int)$value>$max) {
            throw new Failure(422,'INVALID_NUMBER','Enter a positive number in range.');
        }
        return (int)$value;
    }
    private static function position(mixed $value,int $max): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int)$value>$max) {
            throw new Failure(422,'INVALID_NUMBER','Enter a number from 0 to '.$max.'.');
        }
        return (int)$value;
    }
    private static function reason(mixed $value): string
    { return Input::text(trim(Input::text($value,10,500)),10,500); }
    private function audit(string $actor,string $action,string $id,string $reason): void
    { $this->q('INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,?,?,?,?)',
        [$actor,$action,'cabinet',$id,$reason]); }

    public function listing(string $actor,string $search='',string $status=''): array
    {
        $this->authorize($actor);
        $where=''; $args=[$this->org()];
        if ($search!=='') { $where.=' AND (c.cabinet_name ILIKE ? OR c.cabinet_id::text=?)'; array_push($args,'%'.$search.'%',$search); }
        if (in_array($status,['DRAFT','BOUND','REFERENCE'],true)) { $where.=' AND c.status=?'; $args[]=$status; }
        return [
            'items'=>$this->q('SELECT c.cabinet_id AS id,c.cabinet_name AS name,c.status,c.version,c.bound_locker_id,c.bound_location_id,
                (SELECT count(*) FROM cabinet_body b WHERE b.cabinet_id=c.cabinet_id) AS body_count,
                (SELECT count(*) FROM cabinet_box x WHERE x.cabinet_id=c.cabinet_id) AS box_count
                FROM cabinet c WHERE c.organization_id=?'.$where.' ORDER BY c.cabinet_id DESC LIMIT 200',$args)->fetchAll(PDO::FETCH_ASSOC),
            'models'=>$this->q("SELECT model_id AS id,model_name AS name FROM cabinet_body_model WHERE organization_id=? AND status='READY' ORDER BY model_name,model_id",
                [$this->org()])->fetchAll(PDO::FETCH_ASSOC),
            'locations'=>$this->q("SELECT l.id,l.code,l.name,s.name AS site_name FROM locations l
                JOIN installation_sites s ON s.id=l.site_id JOIN lockers k ON k.location_id=l.id
                WHERE l.organization_id=? AND l.status='INACTIVE' AND l.site_mode='DELIVERY_ONLY'
                AND NOT EXISTS(SELECT 1 FROM compartments c WHERE c.locker_id=k.id)
                ORDER BY s.name,l.name LIMIT 100",[$this->org()])->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function detail(string $actor,string $cabinetId): array
    {
        $this->authorize($actor); $cabinetId=self::id($cabinetId);
        $draft=$this->q('SELECT cabinet_id AS id,cabinet_name AS name,status,version,bound_locker_id,bound_location_id,
            legacy_cabinet_id,address,city,state,zipcode
            FROM cabinet WHERE cabinet_id=? AND organization_id=?',[$cabinetId,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$draft) { throw new Failure(404,'SETUP_NOT_FOUND','Cabinet setup not found.'); }
        $bodies=$this->q('SELECT b.body_id AS id,b.body_name AS name,b.display_sequence,b.addr AS controller_address,
            b.protocol_profile,m.model_name,m.model_id
            FROM cabinet_body b JOIN cabinet_body_model m ON m.model_id=b.body_model_id
            WHERE b.cabinet_id=? ORDER BY b.display_sequence',[$cabinetId])->fetchAll(PDO::FETCH_ASSOC);
        $boxes=$this->q('SELECT x.box_id,x.legacy_box_id,x.body_id,b.display_sequence,b.addr AS controller_address,b.protocol_profile,
            x."row" AS display_row,x."column" AS display_column,x.addr AS door_address,x.compartment_id,
            m.model_id AS box_model_id,m.model_name AS box_model_name,m.size_class,m.is_allocable,
            expected.model_name AS template_box_name,slot.addr AS template_door_address,
            CASE WHEN slot.body_box_id IS NULL OR slot.box_model_id<>x.box_model_id
                OR slot.addr IS DISTINCT FROM x.addr THEN 1 ELSE 0 END AS template_difference
            FROM cabinet_box x JOIN cabinet_body b ON b.body_id=x.body_id
            JOIN cabinet_box_model m ON m.model_id=x.box_model_id
            LEFT JOIN cabinet_body_box slot ON slot.body_model_id=b.body_model_id
                AND slot."row"=x."row" AND slot."column"=x."column"
            LEFT JOIN cabinet_box_model expected ON expected.model_id=slot.box_model_id
            WHERE x.cabinet_id=?
            ORDER BY b.display_sequence,x."row",x."column"',[$cabinetId])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($boxes as &$box) { $box['is_allocable']=in_array($box['is_allocable'],['t','true','1',true],true); }
        unset($box);
        return ['draft'=>$draft,'bodies'=>$bodies,'boxes'=>$boxes,'choices'=>$this->listing($actor),'bind_key'=>Secrets::uuid(),
            'reference_review'=>$draft['status']==='REFERENCE'
                ? $this->referenceReview($cabinetId,(string)$draft['legacy_cabinet_id'],$boxes) : null];
    }

    /** Candidate links are displayed for reconciliation, never treated as installation or ownership proof. */
    private function referenceReview(string $cabinetId,string $sourceId,array $boxes): array
    {
        $body=$this->q("SELECT count(*) AS total,
                count(*) FILTER (WHERE protocol_profile='UNVERIFIED') AS unverified_profiles,
                count(*) FILTER (WHERE legacy_body_id IS NULL) AS missing_source_ids
            FROM cabinet_body WHERE cabinet_id=?",[$cabinetId])->fetch(PDO::FETCH_ASSOC);
        $box=$this->q('SELECT count(*) AS total,
                count(*) FILTER (WHERE x.legacy_box_id IS NULL) AS missing_source_ids,
                count(*) FILTER (WHERE m.dimensions_source_unit<>\'MM\' OR m.max_weight_g IS NULL) AS unverified_dimensions
            FROM cabinet_box x JOIN cabinet_box_model m ON m.model_id=x.box_model_id
            WHERE x.cabinet_id=?',[$cabinetId])->fetch(PDO::FETCH_ASSOC);
        $box['template_differences']=count(array_filter($boxes,
            static fn(array $row): bool => (int)$row['template_difference']===1));
        $locationLinks=$this->q('SELECT ll.source_system,ll.source_revision,ll.location_id,
                l.name AS location_name,l.status AS location_status,k.id AS locker_id,
                (SELECT count(*) FROM locker_devices d WHERE d.locker_id=k.id) AS device_count
            FROM legacy_location_links ll JOIN locations l ON l.id=ll.location_id
            LEFT JOIN lockers k ON k.location_id=l.id
            WHERE ll.legacy_cabinet_id=? AND l.organization_id=? ORDER BY ll.id',
            [$sourceId,$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        $boxLinks=(int)$this->q('SELECT count(*) FROM cabinet_box x
            JOIN legacy_compartment_links ll ON ll.legacy_box_id=x.legacy_box_id::text
            JOIN compartments cp ON cp.id=ll.compartment_id
            JOIN lockers k ON k.id=cp.locker_id JOIN locations l ON l.id=k.location_id
            WHERE x.cabinet_id=? AND l.organization_id=?',[$cabinetId,$this->org()])->fetchColumn();
        return ['body'=>$body,'box'=>$box,'location_links'=>$locationLinks,'candidate_box_links'=>$boxLinks];
    }

    public function create(string $actor,array $input): string
    {
        $this->authorize($actor); Input::fields($input,['name','reason'],['code']);
        $name=trim(Input::text($input['name'],1,120)); $reason=self::reason($input['reason']);
        if ($name==='') { throw new Failure(422,'INVALID_NAME','Cabinet name is required.'); }
        return (new Transaction($this->db))->run(function() use($actor,$name,$reason): string {
            $id=(string)$this->q('INSERT INTO cabinet(organization_id,cabinet_name) VALUES (?,?) RETURNING cabinet_id',
                [$this->org(),$name])->fetchColumn();
            $this->audit($actor,'LOCKER_SETUP_CREATED',$id,$reason);
            return $id;
        });
    }

    public function addBody(string $actor,string $cabinetId,array $input): void
    {
        $this->authorize($actor); $cabinetId=self::id($cabinetId);
        Input::fields($input,['body_model_id','name','display_sequence','controller_address','protocol_profile','version','reason'],['code']);
        $modelId=self::id($input['body_model_id']);
        $name=trim(Input::text($input['name'],1,120));
        if ($name==='') { throw new Failure(422,'INVALID_NAME','Body name is required.'); }
        $sequence=self::position($input['display_sequence'],1000);
        $address=self::position($input['controller_address'],255);
        $version=self::number($input['version'],1000000);
        $profile=Input::text($input['protocol_profile'],1,40);
        if (!in_array($profile,['UNVERIFIED','SIMULATED_24','TERMINAL452_V1','TERMINAL452_V2'],true)) {
            throw new Failure(422,'PROFILE_UNVERIFIED','No verified physical controller profile is configured.');
        }
        $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$cabinetId,$modelId,$name,$sequence,$address,$version,$profile,$reason) {
            $draft=$this->q('SELECT status,version FROM cabinet WHERE cabinet_id=? AND organization_id=? FOR UPDATE',
                [$cabinetId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$draft) { throw new Failure(404,'SETUP_NOT_FOUND','Cabinet setup not found.'); }
            if ($draft['status']!=='DRAFT') { throw new Failure(409,'SETUP_BOUND','Bound setup cannot be edited.'); }
            if ((int)$draft['version']!==$version) { throw new Failure(412,'STALE_VERSION','Reload the cabinet setup.'); }
            if (!$this->q("SELECT 1 FROM cabinet_body_model WHERE model_id=? AND organization_id=? AND status='READY'",[$modelId,$this->org()])->fetchColumn()) {
                throw new Failure(404,'MODEL_NOT_READY','Choose a ready body model in this network.');
            }
            $slots=$this->q('SELECT box_model_id,"row" AS display_row,"column" AS display_column,addr AS door_address
                FROM cabinet_body_box WHERE body_model_id=? ORDER BY "row","column"',[$modelId])->fetchAll(PDO::FETCH_ASSOC);
            if (!$slots || in_array(null,array_column($slots,'door_address'),true)) {
                throw new Failure(409,'MODEL_EMPTY','Ready body model needs addressed boxes.');
            }
            if ($this->q('SELECT 1 FROM cabinet_body WHERE cabinet_id=? AND (display_sequence=? OR addr=?)',
                [$cabinetId,$sequence,$address])->fetchColumn()) {
                throw new Failure(409,'BODY_COLLISION','Sequence or controller address is already used.');
            }
            $bodyId=(string)$this->q('INSERT INTO cabinet_body(cabinet_id,body_model_id,body_name,sequence,display_sequence,addr,protocol_profile)
                VALUES (?,?,?,?,?,?,?) RETURNING body_id',[$cabinetId,$modelId,$name,(string)$sequence,$sequence,$address,$profile])->fetchColumn();
            foreach ($slots as $slot) {
                $this->q('INSERT INTO cabinet_box(cabinet_id,body_id,box_model_id,"row","column",addr)
                    VALUES (?,?,?,?,?,?)',[$cabinetId,$bodyId,$slot['box_model_id'],$slot['display_row'],$slot['display_column'],$slot['door_address']]);
            }
            $this->q('UPDATE cabinet SET version=version+1 WHERE cabinet_id=?',[$cabinetId]);
            $this->audit($actor,'LOCKER_SETUP_BODY_ADDED',$cabinetId,$reason.' [body '.$bodyId.', boxes '.count($slots).']');
        });
    }

    public function removeBody(string $actor,string $cabinetId,array $input): void
    {
        $this->authorize($actor); $cabinetId=self::id($cabinetId);
        Input::fields($input,['body_id','version','reason']);
        $bodyId=self::id($input['body_id']); $version=self::number($input['version'],1000000);
        $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$cabinetId,$bodyId,$version,$reason) {
            $draft=$this->q('SELECT status,version FROM cabinet WHERE cabinet_id=? AND organization_id=? FOR UPDATE',
                [$cabinetId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$draft) { throw new Failure(404,'SETUP_NOT_FOUND','Cabinet setup not found.'); }
            if ($draft['status']!=='DRAFT') { throw new Failure(409,'SETUP_BOUND','Bound setup cannot be edited.'); }
            if ((int)$draft['version']!==$version) { throw new Failure(412,'STALE_VERSION','Reload the cabinet setup.'); }
            $this->q('DELETE FROM cabinet_box WHERE cabinet_id=? AND body_id=?',[$cabinetId,$bodyId]);
            if (!$this->q('DELETE FROM cabinet_body WHERE body_id=? AND cabinet_id=? RETURNING body_id',[$bodyId,$cabinetId])->fetchColumn()) {
                throw new Failure(404,'BODY_NOT_FOUND','Body is not in this setup.');
            }
            $this->q('UPDATE cabinet SET version=version+1 WHERE cabinet_id=?',[$cabinetId]);
            $this->audit($actor,'LOCKER_SETUP_BODY_REMOVED',$cabinetId,$reason.' [body '.$bodyId.']');
        });
    }

    public function bind(string $actor,string $cabinetId,array $input): string
    {
        $this->authorize($actor); $cabinetId=self::id($cabinetId);
        Input::fields($input,['location_id','version','idempotency_key','reason']);
        $locationId=self::id($input['location_id']); $version=self::number($input['version'],1000000);
        $key=Input::text($input['idempotency_key'],16,100); $reason=self::reason($input['reason']);
        $hash=hash('sha256',json_encode([$cabinetId,$locationId,$version,$reason],JSON_THROW_ON_ERROR));
        return (new Transaction($this->db))->run(function() use($actor,$cabinetId,$locationId,$version,$key,$reason,$hash): string {
            $draft=$this->q('SELECT * FROM cabinet WHERE cabinet_id=? AND organization_id=? FOR UPDATE',
                [$cabinetId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$draft) { throw new Failure(404,'SETUP_NOT_FOUND','Cabinet setup not found.'); }
            if ($draft['status']==='BOUND') {
                if ($draft['bind_key']===$key && hash_equals($draft['bind_hash'],$hash)) { return (string)$draft['bound_locker_id']; }
                throw new Failure(409,'SETUP_BOUND','Cabinet setup is already bound to a location.');
            }
            if ($draft['status']!=='DRAFT') { throw new Failure(409,'SETUP_REFERENCE','Historical cabinets cannot be bound.'); }
            if ((int)$draft['version']!==$version) { throw new Failure(412,'STALE_VERSION','Reload the cabinet setup.'); }
            $bodies=$this->q('SELECT b.*,m.status AS model_status FROM cabinet_body b
                JOIN cabinet_body_model m ON m.model_id=b.body_model_id AND m.organization_id=?
                WHERE b.cabinet_id=? ORDER BY b.display_sequence',[$this->org(),$cabinetId])->fetchAll(PDO::FETCH_ASSOC);
            if (!$bodies) { throw new Failure(409,'SETUP_EMPTY','Add at least one body before binding.'); }
            $destination=$this->q('SELECT l.status,l.site_mode,l.site_id,k.id AS locker_id
                FROM locations l JOIN lockers k ON k.location_id=l.id
                WHERE l.id=? AND l.organization_id=? FOR UPDATE OF l,k',[$locationId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$destination) { throw new Failure(404,'LOCATION_NOT_FOUND','Choose a locker location in this network.'); }
            if ($destination['status']!=='INACTIVE' || $destination['site_id']===null || $destination['site_mode']!=='DELIVERY_ONLY') {
                throw new Failure(409,'LOCATION_NOT_DRAFT','Binding needs an inactive Delivery-only site location.');
            }
            $lockerId=(string)$destination['locker_id'];
            if ($this->q('SELECT 1 FROM compartments WHERE locker_id=? LIMIT 1',[$lockerId])->fetchColumn()
                || $this->q('SELECT 1 FROM controller_boards WHERE locker_id=? LIMIT 1',[$lockerId])->fetchColumn()
                || $this->q('SELECT 1 FROM locker_devices WHERE locker_id=? LIMIT 1',[$lockerId])->fetchColumn()
                || $this->q('SELECT 1 FROM legacy_location_links WHERE location_id=? LIMIT 1',[$locationId])->fetchColumn()
                || $this->q('SELECT 1 FROM packages WHERE current_location_id=? LIMIT 1',[$locationId])->fetchColumn()) {
                throw new Failure(409,'LOCATION_NOT_EMPTY','Destination has hardware, legacy identity or custody; use a reconciliation workflow.');
            }
            foreach ($bodies as $body) {
                $simulated=$body['protocol_profile']==='SIMULATED_24';
                if ($body['model_status']!=='READY' || !in_array($body['protocol_profile'],['SIMULATED_24','TERMINAL452_V1','TERMINAL452_V2'],true)
                    || ($simulated && !in_array(getenv('APP_ENV'),['test','development'],true))
                    || $body['addr']===null || ($simulated && (int)$body['addr']<1) || (int)$body['display_sequence']<1) {
                    throw new Failure(409,'PROFILE_UNVERIFIED','Use the configured Terminal452 controller or a local simulator.');
                }
                $boxes=$this->q('SELECT x.*,m.dimensions_source_unit FROM cabinet_box x
                    JOIN cabinet_box_model m ON m.model_id=x.box_model_id WHERE x.body_id=? ORDER BY x."row",x."column"',
                    [$body['body_id']])->fetchAll(PDO::FETCH_ASSOC);
                if (!$boxes) { throw new Failure(409,'MODEL_EMPTY','Body has no boxes.'); }
                foreach ($boxes as $box) {
                    $min=$simulated?1:0;$max=$body['protocol_profile']==='TERMINAL452_V1'?27:($simulated?24:9);
                    if ($box['addr']===null || (int)$box['addr']<$min || (int)$box['addr']>$max || $box['dimensions_source_unit']!=='MM') {
                        throw new Failure(409,'MODEL_UNVERIFIED','Layout needs millimeter dimensions and channels supported by the configured controller.');
                    }
                }
            }
            foreach ($bodies as $body) {
                $boardId=(string)$this->q("INSERT INTO controller_boards(locker_id,board_address,protocol_profile,display_sequence,serial_config)
                    VALUES (?,?,?,?,'{}'::jsonb) RETURNING id",[$lockerId,$body['addr'],
                        $body['protocol_profile'],$body['display_sequence']])->fetchColumn();
                $bodyCode='BODY-'.$body['body_id'];
                (new LockerModels($this->db))->materializeBody($actor,$lockerId,(string)$body['body_model_id'],
                    $bodyCode,$body['body_name'],(int)$body['display_sequence'],$boardId,$reason);
                $this->q('UPDATE cabinet_box x SET compartment_id=cp.id,update_time=now() FROM compartments cp
                    WHERE x.body_id=? AND cp.locker_id=? AND cp.code=?||\'-\'||x."row"||\'-\'||x."column"',
                    [$body['body_id'],$lockerId,$bodyCode]);
                if ($this->q('SELECT 1 FROM cabinet_box WHERE body_id=? AND compartment_id IS NULL LIMIT 1',
                    [$body['body_id']])->fetchColumn()) {
                    throw new Failure(409,'BOX_MAPPING_FAILED','Generated cabinet boxes could not be linked to locker inventory.');
                }
            }
            $this->q("UPDATE cabinet SET status='BOUND',version=version+1,bound_location_id=?,
                bound_locker_id=?,bind_key=?,bind_hash=? WHERE cabinet_id=?",[$locationId,$lockerId,$key,$hash,$cabinetId]);
            $this->audit($actor,'LOCKER_SETUP_BOUND',$cabinetId,$reason.' [location '.$locationId.', locker '.$lockerId.']');
            return $lockerId;
        });
    }
}
