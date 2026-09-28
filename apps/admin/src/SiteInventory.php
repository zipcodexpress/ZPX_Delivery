<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets};
use Zpx\Infrastructure\Database\Transaction;

/** Administrative site and physical inventory views. No action commissions equipment or changes custody. */
final class SiteInventory
{
    public function __construct(private PDO $db, private Secrets $crypto) {}
    private function q(string $sql,array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function authorize(string $actor): void { (new Access($this->db))->requireNetworkAdmin($actor); }
    private static function id(string $id): string
    { if (!preg_match('/^[1-9][0-9]{0,17}$/D',$id)) { throw new Failure(422,'INVALID_ID','Invalid ID.'); } return $id; }

    public function sites(string $actor,string $cursor=''): array
    {
        $this->authorize($actor);
        if ($cursor!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)) { throw new Failure(422,'INVALID_CURSOR','Invalid site cursor.'); }
        $args=[$this->org()]; $where='';
        if ($cursor!=='') { $where=' AND s.id<?'; $args[]=$cursor; }
        $rows=$this->q('SELECT s.id,s.code,s.name,s.site_type,s.status,s.version,s.address,
            (SELECT count(*) FROM locations l WHERE l.site_id=s.id) AS location_count
            FROM installation_sites s WHERE s.organization_id=?'.$where.' ORDER BY s.id DESC LIMIT 26',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        return ['items'=>$rows,'next_cursor'=>$more?(string)end($rows)['id']:null,'create_key'=>Secrets::uuid()];
    }

    public function site(string $actor,string $id): array
    {
        $this->authorize($actor); self::id($id);
        $site=$this->q('SELECT * FROM installation_sites WHERE id=? AND organization_id=?',[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$site) { throw new Failure(404,'SITE_NOT_FOUND','Site not found.'); }
        $site['address']=json_decode($site['address'],true,512,JSON_THROW_ON_ERROR);
        $locations=$this->q('SELECT l.id,l.code,l.name,l.kind,l.status,l.address_text,l.site_mode,l.timezone,
            l.overdue_grace_days,l.overdue_daily_cents,l.overdue_cap_cents,
            k.id AS locker_id,k.external_locker_id,
            (SELECT count(*) FROM compartments c WHERE c.locker_id=k.id) AS box_count
            FROM locations l LEFT JOIN lockers k ON k.location_id=l.id
            WHERE l.organization_id=? AND l.site_id=? ORDER BY l.id LIMIT 100',[$this->org(),$id])->fetchAll(PDO::FETCH_ASSOC);
        return ['site'=>$site,'locations'=>$locations,'form_key'=>Secrets::uuid()];
    }

    public function updateDraft(string $actor,string $id,array $input): void
    {
        $this->authorize($actor); self::id($id);
        Input::fields($input,['name','address','timezone','version','reason']);
        $name=trim(Input::text($input['name'],1,160));
        if ($name==='') { throw new Failure(422,'INVALID_INPUT','Site name is required.'); }
        $address=Input::address($input['address']);
        $zone=Input::text($input['timezone'],1,64);
        if (!in_array($zone,\DateTimeZone::listIdentifiers(),true) || !is_string($input['version']) || !ctype_digit($input['version'])) {
            throw new Failure(422,'INVALID_INPUT','Invalid timezone or version.');
        }
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$id,$name,$address,$zone,$input,$reason) {
            $site=$this->q('SELECT status,version,timezone FROM installation_sites WHERE id=? AND organization_id=? FOR UPDATE',[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$site) { throw new Failure(404,'SITE_NOT_FOUND','Site not found.'); }
            if ($site['status']!=='DRAFT') { throw new Failure(409,'SITE_NOT_DRAFT','Only draft sites can be edited.'); }
            if ((int)$site['version']!==(int)$input['version']) { throw new Failure(412,'STALE_VERSION','Refresh the site before editing.'); }
            if ($zone!==$site['timezone'] && $this->q('SELECT 1 FROM locations WHERE site_id=? LIMIT 1',[$id])->fetchColumn()) {
                throw new Failure(409,'SITE_HAS_LOCATIONS','Change location timezones through a reviewed location update.');
            }
            $this->q('UPDATE installation_sites SET name=?,address=?::jsonb,timezone=?,version=version+1,updated_at=now() WHERE id=?',
                [$name,json_encode($address,JSON_THROW_ON_ERROR),$zone,$id]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'SITE_DRAFT_UPDATED','site',?,?)",[$actor,$id,$reason]);
        });
    }

    public function create(string $actor,array $input,string $key): string
    {
        $this->authorize($actor);
        Input::fields($input,['code','name','site_type','address','timezone','reason']);
        $code=trim(Input::text($input['code'],3,40));
        if (!preg_match('/^[A-Z][A-Z0-9-]{2,39}$/D',$code)) { throw new Failure(422,'INVALID_INPUT','Use an uppercase site code.'); }
        $name=trim(Input::text($input['name'],1,160));
        if ($name==='') { throw new Failure(422,'INVALID_INPUT','Site name is required.'); }
        $types=['APARTMENT','CONVENIENCE_STORE','SHOPPING_MALL','SCHOOL','COMPANY_BUILDING','HUB'];
        if (!in_array($input['site_type'],$types,true)) { throw new Failure(422,'INVALID_INPUT','Invalid site type.'); }
        $address=Input::address($input['address']);
        $zone=Input::text($input['timezone'],1,64);
        if (!in_array($zone,\DateTimeZone::listIdentifiers(),true)) { throw new Failure(422,'INVALID_INPUT','Invalid timezone.'); }
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        Input::text($key,16,100);
        return (new Transaction($this->db))->run(function () use ($actor,$code,$name,$input,$address,$zone,$reason,$key) {
            $scope='site-create:'.$this->org().':'.$actor;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('site-create',json_encode([$code,$name,$input['site_type'],$address,$zone,$reason],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','Request key was used with different details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR)['id'];
            }
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['site-code:'.$this->org().':'.$code]);
            if ($this->q('SELECT 1 FROM installation_sites WHERE organization_id=? AND code=?',[$this->org(),$code])->fetchColumn()) {
                throw new Failure(409,'SITE_CODE_EXISTS','Site code already exists.');
            }
            $id=(string)$this->q("INSERT INTO installation_sites(organization_id,code,name,site_type,address,timezone,created_by)
                VALUES (?,?,?,?,?::jsonb,?,?) RETURNING id",[$this->org(),$code,$name,$input['site_type'],json_encode($address,JSON_THROW_ON_ERROR),$zone,$actor])->fetchColumn();
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'SITE_DRAFT_CREATED','site',?,?)",[$actor,$id,$reason]);
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),201,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode(['id'=>$id],JSON_THROW_ON_ERROR)]);
            return $id;
        });
    }

    public function setOverdueDraft(string $actor,string $id,array $input): void
    {
        $this->authorize($actor); self::id($id);
        Input::fields($input,['grace_days','daily_cents','cap_cents','version','reason']);
        foreach (['grace_days'=>365,'daily_cents'=>1000000,'cap_cents'=>100000000,'version'=>PHP_INT_MAX] as $field=>$max) {
            if (!is_string($input[$field]) || !ctype_digit($input[$field]) || (int)$input[$field]>$max) {
                throw new Failure(422,'INVALID_INPUT','Invalid overdue policy value.');
            }
        }
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function () use ($actor,$id,$input,$reason) {
            $site=$this->q('SELECT status,version FROM installation_sites WHERE id=? AND organization_id=? FOR UPDATE',[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$site) { throw new Failure(404,'SITE_NOT_FOUND','Site not found.'); }
            if ($site['status']!=='DRAFT') { throw new Failure(409,'SITE_NOT_DRAFT','Only draft site policy can be edited.'); }
            if ((int)$site['version']!==(int)$input['version']) { throw new Failure(412,'STALE_VERSION','Refresh the site before editing.'); }
            $this->q('UPDATE installation_sites SET overdue_grace_days=?,overdue_daily_cents=?,overdue_cap_cents=?,version=version+1,updated_at=now() WHERE id=?',
                [$input['grace_days'],$input['daily_cents'],$input['cap_cents'],$id]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'SITE_OVERDUE_DRAFT_UPDATED','site',?,?)",[$actor,$id,$reason]);
        });
    }

    public function addLocation(string $actor,string $siteId,array $input,string $key): string
    {
        $this->authorize($actor); self::id($siteId);
        Input::fields($input,['code','name','address_text','reason']);
        $code=trim(Input::text($input['code'],3,40));
        if (!preg_match('/^[A-Z][A-Z0-9-]{2,39}$/D',$code)) { throw new Failure(422,'INVALID_INPUT','Use an uppercase location code.'); }
        $name=trim(Input::text($input['name'],1,160));
        $address=trim(Input::text($input['address_text'],1,500));
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        if ($name==='' || $address==='') { throw new Failure(422,'INVALID_INPUT','Name and address are required.'); }
        Input::text($key,16,100);
        return (new Transaction($this->db))->run(function () use ($actor,$siteId,$code,$name,$address,$reason,$key) {
            $scope='site-location-create:'.$this->org().':'.$actor.':'.$siteId;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('site-location-create',json_encode([$siteId,$code,$name,$address,$reason],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','Request key was used with different details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR)['id'];
            }
            $site=$this->q('SELECT timezone,status FROM installation_sites WHERE id=? AND organization_id=? FOR UPDATE',[$siteId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$site) { throw new Failure(404,'SITE_NOT_FOUND','Site not found.'); }
            if ($site['status']!=='DRAFT') { throw new Failure(409,'SITE_NOT_DRAFT','Location provisioning requires a draft site.'); }
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['location-code:'.$code]);
            if ($this->q('SELECT 1 FROM locations WHERE code=?',[$code])->fetchColumn()) { throw new Failure(409,'LOCATION_CODE_EXISTS','Location code already exists.'); }
            $id=(string)$this->q("INSERT INTO locations(organization_id,site_id,code,name,kind,address_text,timezone,site_mode,status,access_policy)
                VALUES (?,?,?,?,'LOCKER',?,?,'DELIVERY_ONLY','INACTIVE','{\"public_shipping_enabled\":false}'::jsonb) RETURNING id",
                [$this->org(),$siteId,$code,$name,$address,$site['timezone']])->fetchColumn();
            $locker=(string)$this->q('INSERT INTO lockers(location_id) VALUES (?) RETURNING id',[$id])->fetchColumn();
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCATION_DRAFT_CREATED','location',?,?)",[$actor,$id,$reason]);
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),201,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode(['id'=>$id,'locker_id'=>$locker],JSON_THROW_ON_ERROR)]);
            return $id;
        });
    }

    public function setLocationOverdueDraft(string $actor,string $siteId,string $locationId,array $input): void
    {
        $this->authorize($actor); self::id($siteId); self::id($locationId);
        Input::fields($input,['grace_days','daily_cents','cap_cents','reason']);
        $empty=$input['grace_days']==='' && $input['daily_cents']==='' && $input['cap_cents']==='';
        foreach (['grace_days'=>365,'daily_cents'=>1000000,'cap_cents'=>100000000] as $field=>$max) {
            if (!$empty && (!is_string($input[$field]) || !ctype_digit($input[$field]) || (int)$input[$field]>$max)) {
                throw new Failure(422,'INVALID_INPUT','Invalid overdue policy value.');
            }
        }
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$siteId,$locationId,$input,$reason,$empty) {
            $row=$this->q('SELECT l.status,s.status AS site_status FROM locations l JOIN installation_sites s ON s.id=l.site_id
                WHERE l.id=? AND l.site_id=? AND l.organization_id=? FOR UPDATE OF l,s',[$locationId,$siteId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'LOCATION_NOT_FOUND','Location not found.'); }
            if ($row['status']==='ACTIVE' || $row['site_status']!=='DRAFT') {
                throw new Failure(409,'LOCATION_NOT_DRAFT','Only inactive locations at draft sites can have a policy draft edited.');
            }
            $this->q('UPDATE locations SET overdue_grace_days=?,overdue_daily_cents=?,overdue_cap_cents=? WHERE id=?',
                [$empty?null:$input['grace_days'],$empty?null:$input['daily_cents'],$empty?null:$input['cap_cents'],$locationId]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCATION_OVERDUE_DRAFT_UPDATED','location',?,?)",[$actor,$locationId,$reason]);
        });
    }

    public function deactivateLocation(string $actor,string $siteId,string $locationId,string $reason): void
    {
        $this->authorize($actor); self::id($siteId); self::id($locationId);
        $reason=Input::text(trim(Input::text($reason,10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$siteId,$locationId,$reason) {
            $row=$this->q('SELECT status FROM locations WHERE id=? AND site_id=? AND organization_id=? FOR UPDATE',
                [$locationId,$siteId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'LOCATION_NOT_FOUND','Location not found.'); }
            if ($row['status']!=='ACTIVE') { throw new Failure(409,'LOCATION_NOT_ACTIVE','Location is not active.'); }
            $this->q("UPDATE locations SET status='INACTIVE' WHERE id=?",[$locationId]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCATION_DEACTIVATED','location',?,?)",[$actor,$locationId,$reason]);
        });
    }

    public function locker(string $actor,string $id): array
    {
        $this->authorize($actor); self::id($id);
        $locker=$this->q('SELECT k.id,k.external_locker_id,l.id AS location_id,l.name AS location_name,l.status AS location_status,
            s.id AS site_id,s.name AS site_name,s.status AS site_status FROM lockers k JOIN locations l ON l.id=k.location_id
            LEFT JOIN installation_sites s ON s.id=l.site_id WHERE k.id=? AND l.organization_id=?',[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$locker) { throw new Failure(404,'LOCKER_NOT_FOUND','Locker not found.'); }
        return ['locker'=>$locker,'bodies'=>$this->q('SELECT id,code,display_sequence,status FROM locker_body_modules WHERE locker_id=? ORDER BY display_sequence',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'modules'=>$this->q('SELECT m.id,m.code,m.body_module_id,m.display_sequence,m.status FROM locker_box_modules m WHERE m.locker_id=? ORDER BY m.body_module_id,m.display_sequence',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'boxes'=>$this->q('SELECT c.id,c.code,c.width_mm,c.height_mm,c.depth_mm,c.max_weight_g,c.status,c.box_module_id,
                b.board_address,c.door_address,c.display_row,c.display_column,
                (SELECT count(*) FROM compartment_claims cc WHERE cc.compartment_id=c.id) AS claim_count
                FROM compartments c LEFT JOIN controller_boards b ON b.id=c.controller_board_id
                WHERE c.locker_id=? ORDER BY c.id LIMIT 500',[$id])->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function lockLocker(string $actor,string $id): void
    {
        $this->authorize($actor); self::id($id);
        if (!$this->q('SELECT k.id FROM lockers k JOIN locations l ON l.id=k.location_id WHERE k.id=? AND l.organization_id=? FOR UPDATE OF k',
            [$id,$this->org()])->fetchColumn()) { throw new Failure(404,'LOCKER_NOT_FOUND','Locker not found.'); }
    }
    private static function moduleCode(mixed $value): string
    {
        $code=trim(Input::text($value,2,40));
        if (!preg_match('/^[A-Z][A-Z0-9-]{1,39}$/D',$code)) { throw new Failure(422,'INVALID_INPUT','Use an uppercase module code.'); }
        return $code;
    }
    private static function position(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int)$value<1 || (int)$value>1000) {
            throw new Failure(422,'INVALID_INPUT','Position must be from 1 to 1000.');
        }
        return (int)$value;
    }
    public function addBody(string $actor,string $locker,array $input): void
    {
        Input::fields($input,['code','position','reason']);
        $code=self::moduleCode($input['code']); $position=self::position($input['position']);
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$locker,$code,$position,$reason) {
            $this->lockLocker($actor,$locker);
            if ($this->q('SELECT 1 FROM locker_body_modules WHERE locker_id=? AND (code=? OR display_sequence=?)',[$locker,$code,$position])->fetchColumn()) {
                throw new Failure(409,'MODULE_EXISTS','Body code or position already exists.');
            }
            $id=$this->q("INSERT INTO locker_body_modules(locker_id,code,display_sequence) VALUES (?,?,?) RETURNING id",[$locker,$code,$position])->fetchColumn();
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCKER_BODY_DRAFT_CREATED','locker',?,?)",[$actor,$locker,$reason.' [body '.$id.']']);
        });
    }
    public function addBoxModule(string $actor,string $locker,array $input): void
    {
        Input::fields($input,['body_id','code','position','reason']);
        $body=self::id((string)$input['body_id']); $code=self::moduleCode($input['code']);
        $position=self::position($input['position']);
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$locker,$body,$code,$position,$reason) {
            $this->lockLocker($actor,$locker);
            if (!$this->q('SELECT 1 FROM locker_body_modules WHERE id=? AND locker_id=?',[$body,$locker])->fetchColumn()) {
                throw new Failure(404,'BODY_NOT_FOUND','Body not found at this locker.');
            }
            if ($this->q('SELECT 1 FROM locker_box_modules WHERE locker_id=? AND (code=? OR (body_module_id=? AND display_sequence=?))',
                [$locker,$code,$body,$position])->fetchColumn()) { throw new Failure(409,'MODULE_EXISTS','Box module code or position already exists.'); }
            $id=$this->q('INSERT INTO locker_box_modules(locker_id,body_module_id,code,display_sequence) VALUES (?,?,?,?) RETURNING id',
                [$locker,$body,$code,$position])->fetchColumn();
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCKER_BOX_MODULE_DRAFT_CREATED','locker',?,?)",[$actor,$locker,$reason.' [module '.$id.']']);
        });
    }
    public function addDraftBox(string $actor,string $locker,array $input): void
    {
        Input::fields($input,['code','module_id','width_mm','height_mm','depth_mm','max_weight_g','reason']);
        $code=self::moduleCode($input['code']); $module=self::id((string)$input['module_id']);
        $sizes=[];
        foreach (['width_mm','height_mm','depth_mm','max_weight_g'] as $field) {
            $value=$input[$field];
            if (!is_string($value) || !ctype_digit($value) || (int)$value<1 || (int)$value>100000) {
                throw new Failure(422,'INVALID_INPUT','Box measurements must be positive whole numbers.');
            }
            $sizes[]=(int)$value;
        }
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$locker,$code,$module,$sizes,$reason) {
            $this->lockLocker($actor,$locker);
            $status=$this->q('SELECT l.status FROM lockers k JOIN locations l ON l.id=k.location_id WHERE k.id=?',[$locker])->fetchColumn();
            if ($status!=='INACTIVE') { throw new Failure(409,'LOCKER_LOCATION_ACTIVE','New boxes require an inactive locker location.'); }
            if (!$this->q('SELECT 1 FROM locker_box_modules WHERE id=? AND locker_id=?',[$module,$locker])->fetchColumn()) {
                throw new Failure(404,'MODULE_NOT_FOUND','Box module not found at this locker.');
            }
            if ($this->q('SELECT 1 FROM compartments WHERE locker_id=? AND code=?',[$locker,$code])->fetchColumn()) {
                throw new Failure(409,'BOX_CODE_EXISTS','Box code already exists at this locker.');
            }
            $id=$this->q("INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status,box_module_id)
                VALUES (?,?,?,?,?,?,'FROZEN',?) RETURNING id",[$locker,$code,...$sizes,$module])->fetchColumn();
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCKER_BOX_DRAFT_CREATED','locker',?,?)",[$actor,$locker,$reason.' [box '.$id.']']);
        });
    }
    public function assignBox(string $actor,string $locker,array $input): void
    {
        Input::fields($input,['box_id','module_id','reason']);
        $box=self::id((string)$input['box_id']); $module=self::id((string)$input['module_id']);
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$locker,$box,$module,$reason) {
            $this->lockLocker($actor,$locker);
            if (!$this->q('SELECT 1 FROM locker_box_modules WHERE id=? AND locker_id=?',[$module,$locker])->fetchColumn()) {
                throw new Failure(404,'MODULE_NOT_FOUND','Box module not found at this locker.');
            }
            $row=$this->q('SELECT box_module_id FROM compartments WHERE id=? AND locker_id=? FOR UPDATE',[$box,$locker])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'BOX_NOT_FOUND','Box not found at this locker.'); }
            if ($this->q('SELECT 1 FROM compartment_claims WHERE compartment_id=?',[$box])->fetchColumn()
                || $this->q("SELECT 1 FROM locker_sessions WHERE compartment_id=? AND status IN ('READY','OPEN','CLOSED','UNKNOWN','CONFIRMED') LIMIT 1",[$box])->fetchColumn()) {
                throw new Failure(409,'BOX_IN_USE','Reconcile the box before changing its inventory grouping.');
            }
            $this->q('UPDATE compartments SET box_module_id=? WHERE id=?',[$module,$box]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCKER_BOX_GROUPED','locker',?,?)",[$actor,$locker,$reason.' [box '.$box.']']);
        });
    }
}
