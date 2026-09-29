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
            op.display_name AS owner_name,
            (SELECT count(*) FROM locations l WHERE l.site_id=s.id) AS location_count
            FROM installation_sites s LEFT JOIN network_partners op ON op.id=s.owner_partner_id
            WHERE s.organization_id=?'.$where.' ORDER BY s.id DESC LIMIT 26',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        return ['items'=>$rows,'next_cursor'=>$more?(string)end($rows)['id']:null,'create_key'=>Secrets::uuid()];
    }

    public function lockers(string $actor,string $cursor=''): array
    {
        $this->authorize($actor);
        if ($cursor!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)) { throw new Failure(422,'INVALID_CURSOR','Invalid locker cursor.'); }
        $args=[$this->org()]; $where='';
        if ($cursor!=='') { $where=' AND k.id<?'; $args[]=$cursor; }
        $rows=$this->q('SELECT k.id,k.external_locker_id,l.name AS location_name,l.status AS location_status,
            s.id AS site_id,s.name AS site_name,s.status AS site_status,
            (SELECT count(*) FROM compartments c WHERE c.locker_id=k.id) AS box_count
            FROM lockers k JOIN locations l ON l.id=k.location_id
            LEFT JOIN installation_sites s ON s.id=l.site_id
            WHERE l.organization_id=?'.$where.' ORDER BY k.id DESC LIMIT 26',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        return ['items'=>$rows,'next_cursor'=>$more?(string)end($rows)['id']:null];
    }

    public function site(string $actor,string $id): array
    {
        $this->authorize($actor); self::id($id);
        $site=$this->q('SELECT s.*,op.display_name AS owner_name,hp.display_name AS host_name
            FROM installation_sites s LEFT JOIN network_partners op ON op.id=s.owner_partner_id
            LEFT JOIN network_partners hp ON hp.id=s.host_partner_id
            WHERE s.id=? AND s.organization_id=?',[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$site) { throw new Failure(404,'SITE_NOT_FOUND','Site not found.'); }
        $site['address']=json_decode($site['address'],true,512,JSON_THROW_ON_ERROR);
        $partners=$this->q("SELECT p.id,p.display_name FROM network_partners p JOIN network_partner_roles r ON r.partner_id=p.id
            WHERE p.organization_id=? AND r.role_code='HOST' AND p.status IN ('DRAFT','ACTIVE') ORDER BY p.display_name LIMIT 100",[$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        $owners=$this->q("SELECT p.id,p.display_name FROM network_partners p JOIN network_partner_roles r ON r.partner_id=p.id
            WHERE p.organization_id=? AND r.role_code='SITE_OWNER' AND p.status IN ('DRAFT','ACTIVE') ORDER BY p.display_name LIMIT 100",[$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        $contacts=$this->q('SELECT a.id,a.role_code,a.is_primary,a.starts_on,a.ends_on,c.display_name,c.role_title,c.email_ciphertext,c.phone_ciphertext
            FROM site_contact_assignments a JOIN network_contacts c ON c.id=a.contact_id
            WHERE a.site_id=? AND a.organization_id=? ORDER BY a.id DESC LIMIT 100',[$id,$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($contacts as &$contact) {
            $contact['email']=$contact['email_ciphertext'] ? $this->crypto->decrypt($contact['email_ciphertext']) : null;
            $contact['phone']=$contact['phone_ciphertext'] ? $this->crypto->decrypt($contact['phone_ciphertext']) : null;
            unset($contact['email_ciphertext'],$contact['phone_ciphertext']);
        }
        unset($contact);
        $locations=$this->q('SELECT l.id,l.code,l.name,l.kind,l.status,l.address_text,l.site_mode,l.timezone,
            l.overdue_grace_days,l.overdue_daily_cents,l.overdue_cap_cents,
            CASE WHEN EXISTS(SELECT 1 FROM audit_events a WHERE a.entity_type=\'location\' AND a.entity_id=l.id::text
                AND a.action=\'LOCATION_DEACTIVATED\') THEN 1 ELSE 0 END AS can_reactivate,
            k.id AS locker_id,k.external_locker_id,
            (SELECT count(*) FROM compartments c WHERE c.locker_id=k.id) AS box_count
            FROM locations l LEFT JOIN lockers k ON k.location_id=l.id
            WHERE l.organization_id=? AND l.site_id=? ORDER BY l.id LIMIT 100',[$this->org(),$id])->fetchAll(PDO::FETCH_ASSOC);
        return ['site'=>$site,'locations'=>$locations,'partners'=>$partners,'owners'=>$owners,'contacts'=>$contacts,'form_key'=>Secrets::uuid()];
    }

    public function setRelationship(string $actor,string $id,array $input): void
    {
        $this->authorize($actor); self::id($id);
        Input::fields($input,['owner_partner_id','host_partner_id','contract_reference','starts_on','ends_on','version','reason']);
        $owner=$input['owner_partner_id']===''?null:self::id((string)$input['owner_partner_id']);
        $host=$input['host_partner_id']===''?null:self::id((string)$input['host_partner_id']);
        $reference=trim(Input::text($input['contract_reference'],0,160));
        $reference=$reference===''?null:$reference;
        $start=$input['starts_on']===''?null:self::date($input['starts_on']);
        $end=$input['ends_on']===''?null:self::date($input['ends_on']);
        if ($end!==null && ($start===null || $end<$start) || !is_string($input['version']) || !ctype_digit($input['version'])) {
            throw new Failure(422,'INVALID_INPUT','Invalid contract dates or version.');
        }
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$id,$owner,$host,$reference,$start,$end,$reason,$input) {
            $site=$this->q('SELECT status,version FROM installation_sites WHERE id=? AND organization_id=? FOR UPDATE',[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$site) { throw new Failure(404,'SITE_NOT_FOUND','Site not found.'); }
            if ($site['status']!=='DRAFT') { throw new Failure(409,'SITE_NOT_DRAFT','Only draft site relationships can be edited.'); }
            if ((int)$site['version']!==(int)$input['version']) { throw new Failure(412,'STALE_VERSION','Refresh the site before editing.'); }
            if ($host!==null && !$this->q("SELECT 1 FROM network_partners p JOIN network_partner_roles r ON r.partner_id=p.id
                WHERE p.id=? AND p.organization_id=? AND r.role_code='HOST' AND p.status IN ('DRAFT','ACTIVE')",[$host,$this->org()])->fetchColumn()) {
                throw new Failure(422,'INVALID_HOST','Choose a host partner in this network.');
            }
            if ($owner!==null && !$this->q("SELECT 1 FROM network_partners p JOIN network_partner_roles r ON r.partner_id=p.id
                WHERE p.id=? AND p.organization_id=? AND r.role_code='SITE_OWNER' AND p.status IN ('DRAFT','ACTIVE')",[$owner,$this->org()])->fetchColumn()) {
                throw new Failure(422,'INVALID_OWNER','Choose a property owner in this network.');
            }
            $this->q('UPDATE installation_sites SET owner_partner_id=?,host_partner_id=?,contract_reference=?,starts_on=?,ends_on=?,version=version+1,updated_at=now() WHERE id=?',
                [$owner,$host,$reference,$start,$end,$id]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'SITE_RELATIONSHIP_UPDATED','site',?,?)",[$actor,$id,$reason]);
        });
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value) || \DateTimeImmutable::createFromFormat('!Y-m-d',$value)?->format('Y-m-d')!==$value) {
            throw new Failure(422,'INVALID_DATE','Use a valid calendar date.');
        }
        return $value;
    }

    public function addContact(string $actor,string $siteId,array $input): void
    {
        $this->authorize($actor); self::id($siteId);
        Input::fields($input,['name','role_title','email','phone','role_code','is_primary','reason']);
        $name=trim(Input::text($input['name'],2,160));
        if ($name==='') { throw new Failure(422,'INVALID_INPUT','Contact name is required.'); }
        $title=trim(Input::text($input['role_title'],0,100));
        $email=trim(Input::text($input['email'],0,254));
        $phone=trim(Input::text($input['phone'],0,30));
        if ($email==='' && $phone==='') { throw new Failure(422,'INVALID_INPUT','Enter email or phone.'); }
        if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new Failure(422,'INVALID_INPUT','Invalid email.'); }
        if ($phone!=='' && !preg_match('/^[+0-9() .-]{7,30}$/D',$phone)) { throw new Failure(422,'INVALID_INPUT','Invalid phone.'); }
        $role=$input['role_code'];
        if (!in_array($role,['PROPERTY_MANAGER','SITE_HOST','SITE_STAFF','SECURITY','ACCESS_ASSISTANCE','EMERGENCY','MAINTENANCE'],true)) {
            throw new Failure(422,'INVALID_INPUT','Invalid site contact role.');
        }
        if (!in_array($input['is_primary'],['0','1'],true)) { throw new Failure(422,'INVALID_INPUT','Invalid primary contact choice.'); }
        $primary=$input['is_primary']==='1';
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$siteId,$name,$title,$email,$phone,$role,$primary,$reason) {
            if (!$this->q('SELECT 1 FROM installation_sites WHERE id=? AND organization_id=? FOR UPDATE',[$siteId,$this->org()])->fetchColumn()) {
                throw new Failure(404,'SITE_NOT_FOUND','Site not found.');
            }
            if ($primary && $this->q('SELECT 1 FROM site_contact_assignments WHERE site_id=? AND role_code=? AND is_primary AND ends_on IS NULL',[$siteId,$role])->fetchColumn()) {
                throw new Failure(409,'PRIMARY_EXISTS','This site already has a primary contact for that role.');
            }
            $contact=$this->q('INSERT INTO network_contacts(organization_id,display_name,role_title,email_ciphertext,phone_ciphertext) VALUES (?,?,?,?,?) RETURNING id',
                [$this->org(),$name,$title?:null,$email!==''?$this->crypto->encrypt($email):null,$phone!==''?$this->crypto->encrypt($phone):null])->fetchColumn();
            $this->q('INSERT INTO site_contact_assignments(organization_id,site_id,contact_id,role_code,is_primary) VALUES (?,?,?,?,?)',
                [$this->org(),$siteId,$contact,$role,$primary]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'SITE_CONTACT_ADDED','site',?,?)",[$actor,$siteId,$reason]);
        });
    }

    public function archiveContact(string $actor,string $siteId,string $assignmentId,string $reason): void
    {
        $this->authorize($actor); self::id($siteId); self::id($assignmentId);
        $reason=Input::text(trim(Input::text($reason,10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$siteId,$assignmentId,$reason) {
            $row=$this->q('SELECT a.contact_id,a.ends_on,a.is_primary,s.status AS site_status FROM site_contact_assignments a JOIN installation_sites s ON s.id=a.site_id
                WHERE a.id=? AND a.site_id=? AND s.organization_id=? FOR UPDATE OF a',[$assignmentId,$siteId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'CONTACT_NOT_FOUND','Site contact not found.'); }
            if ($row['ends_on']!==null) { throw new Failure(409,'CONTACT_ARCHIVED','Site contact is already archived.'); }
            if ($row['is_primary'] && $row['site_status']==='ACTIVE') {
                throw new Failure(409,'PRIMARY_REQUIRED','Replace the active site primary contact before archiving it.');
            }
            $this->q('UPDATE site_contact_assignments SET ends_on=CURRENT_DATE WHERE id=?',[$assignmentId]);
            $this->q("UPDATE network_contacts SET status='ARCHIVED' WHERE id=? AND NOT EXISTS
                (SELECT 1 FROM site_contact_assignments WHERE contact_id=? AND ends_on IS NULL)",[$row['contact_id'],$row['contact_id']]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'SITE_CONTACT_ARCHIVED','site',?,?)",[$actor,$siteId,$reason]);
        });
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

    public function reactivateLocation(string $actor,string $siteId,string $locationId,string $reason): void
    {
        $this->authorize($actor); self::id($siteId); self::id($locationId);
        $reason=Input::text(trim(Input::text($reason,10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$siteId,$locationId,$reason) {
            $row=$this->q('SELECT l.status,s.status AS site_status FROM locations l JOIN installation_sites s ON s.id=l.site_id
                WHERE l.id=? AND l.site_id=? AND l.organization_id=? FOR UPDATE OF l,s',
                [$locationId,$siteId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'LOCATION_NOT_FOUND','Location not found.'); }
            if ($row['status']!=='INACTIVE' || !in_array($row['site_status'],['DRAFT','ACTIVE'],true)) {
                throw new Failure(409,'LOCATION_NOT_REACTIVATABLE','This location cannot be reactivated.');
            }
            if (!$this->q("SELECT 1 FROM audit_events WHERE entity_type='location' AND entity_id=?
                AND action='LOCATION_DEACTIVATED' LIMIT 1",[$locationId])->fetchColumn()) {
                throw new Failure(409,'COMMISSIONING_REQUIRED','New locations require a commissioning workflow before activation.');
            }
            $this->q("UPDATE locations SET status='ACTIVE' WHERE id=?",[$locationId]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCATION_REACTIVATED','location',?,?)",[$actor,$locationId,$reason]);
        });
    }

    public function locker(string $actor,string $id,string $cursor=''): array
    {
        $this->authorize($actor); self::id($id);
        if ($cursor!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)) { throw new Failure(422,'INVALID_CURSOR','Invalid package cursor.'); }
        $locker=$this->q('SELECT k.id,k.external_locker_id,l.id AS location_id,l.name AS location_name,l.status AS location_status,
            s.id AS site_id,s.name AS site_name,s.status AS site_status FROM lockers k JOIN locations l ON l.id=k.location_id
            LEFT JOIN installation_sites s ON s.id=l.site_id WHERE k.id=? AND l.organization_id=?',[$id,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$locker) { throw new Failure(404,'LOCKER_NOT_FOUND','Locker not found.'); }
        $boxes=$this->q('SELECT c.id,c.code,c.width_mm,c.height_mm,c.depth_mm,c.max_weight_g,c.status,c.box_module_id,c.box_model_id,
                bm.size_class,
                m.code AS module_code,body.code AS body_code,
                b.board_address,c.door_address,c.display_row,c.display_column,o.owner AS partition,
                cc.state AS claim_state,cc.expires_at AS claim_expires_at,p.id AS package_id,p.state AS package_state,
                p.custodian_type,p.custodian_ref,p.current_location_id,si.si,sh.id AS shipment_id,sh.public_reference,
                sh.development_only,ls.status AS session_status
                FROM compartments c LEFT JOIN locker_box_models bm ON bm.id=c.box_model_id
                LEFT JOIN locker_box_modules m ON m.id=c.box_module_id
                LEFT JOIN locker_body_modules body ON body.id=m.body_module_id
                LEFT JOIN controller_boards b ON b.id=c.controller_board_id
                LEFT JOIN compartment_ownership o ON o.compartment_id=c.id
                LEFT JOIN compartment_claims cc ON cc.compartment_id=c.id
                LEFT JOIN packages p ON p.id=cc.package_id
                LEFT JOIN shipments sh ON sh.id=p.shipment_id
                LEFT JOIN shipping_identifiers si ON si.package_id=p.id
                LEFT JOIN LATERAL (SELECT status FROM locker_sessions WHERE compartment_id=c.id ORDER BY id DESC LIMIT 1) ls ON true
                WHERE c.locker_id=? ORDER BY c.display_row NULLS LAST,c.display_column NULLS LAST,c.id',[$id])->fetchAll(PDO::FETCH_ASSOC);
        $occupancy=['available'=>0,'occupied'=>0,'reserved'=>0,'review'=>0,'unavailable'=>0];
        $claimedPackages=[];
        foreach ($boxes as &$box) {
            if ($box['package_id']!==null) { $claimedPackages[(string)$box['package_id']]=['code'=>$box['code'],'state'=>$box['claim_state']]; }
            $claim=$box['claim_state'];
            $matches=$box['custodian_type']==='LOCKER' && (string)$box['current_location_id']===(string)$locker['location_id']
                && (string)$box['custodian_ref']===$id;
            $box['occupancy']=match (true) {
                $claim==='OCCUPIED' && $matches && $box['status']==='ACTIVE' && $box['partition']==='DELIVERY'
                    && $box['board_address']!==null && $box['door_address']!==null => 'OCCUPIED',
                $claim==='HELD' && $box['status']==='ACTIVE' && $box['partition']==='DELIVERY'
                    && $box['board_address']!==null && $box['door_address']!==null
                    && ($box['claim_expires_at']===null || strtotime($box['claim_expires_at'])>time()) => 'RESERVED',
                $claim!==null || $box['session_status']==='UNKNOWN' => 'REVIEW',
                $box['status']==='ACTIVE' && $box['partition']==='DELIVERY' && $locker['location_status']==='ACTIVE'
                    && $box['board_address']!==null && $box['door_address']!==null => 'AVAILABLE',
                default => 'UNAVAILABLE',
            };
            $occupancy[strtolower($box['occupancy'])]++;
        }
        unset($box);
        $unclaimedCustody=(int)$this->q("SELECT count(*) FROM packages p JOIN shipments sh ON sh.id=p.shipment_id
            WHERE sh.organization_id=? AND p.current_location_id=? AND p.custodian_type='LOCKER'
            AND NOT EXISTS (SELECT 1 FROM compartment_claims cc WHERE cc.package_id=p.id)",
            [$this->org(),$locker['location_id']])->fetchColumn();
        $args=[$locker['location_id'],$locker['location_id'],$locker['location_id'],$this->org()];
        $where='';
        if ($cursor!=='') { $where=' AND p.id<?'; $args[]=$cursor; }
        $packages=$this->q('SELECT p.id,p.state,p.custodian_type,p.custodian_ref,p.current_location_id,
                sh.id AS shipment_id,sh.public_reference,sh.origin_location_id,sh.destination_location_id,sh.development_only,si.si
                FROM packages p JOIN shipments sh ON sh.id=p.shipment_id
                LEFT JOIN shipping_identifiers si ON si.package_id=p.id
                WHERE (sh.origin_location_id=? OR sh.destination_location_id=? OR p.current_location_id=?)
                AND sh.organization_id=?'.$where.' ORDER BY p.id DESC LIMIT 51',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($packages)>50; $packages=array_slice($packages,0,50);
        foreach ($packages as &$package) {
            $package['route_role']=$package['origin_location_id']==$locker['location_id']
                ? ($package['destination_location_id']==$locker['location_id']?'Origin and destination':'Origin') : 'Destination';
            if ($package['origin_location_id']!=$locker['location_id'] && $package['destination_location_id']!=$locker['location_id']) { $package['route_role']='Current location only'; }
            $claim=$claimedPackages[(string)$package['id']] ?? null;
            $package['box_code']=$claim['code'] ?? null;
            $package['phase']=match ($package['state']) {
                'CREATED'=>'Shipment created',
                'AT_ORIGIN'=>'Initial outbound · awaiting driver pickup',
                'INBOUND_CUSTODY'=>'Driver inbound',
                'AT_HUB'=>'At hub',
                'STAGED'=>'Hub staging',
                'OUTBOUND_CUSTODY'=>'Driver outbound',
                'AT_DESTINATION'=>'Waiting for final pickup',
                'COLLECTED'=>'Recipient collected',
                default=>$package['state'],
            };
            $package['location_evidence']=$claim!==null ? 'Box claim '.$claim['state'] :
                ($package['custodian_type']==='LOCKER' && (string)$package['current_location_id']===(string)$locker['location_id']
                    ? 'Locker custody; no box claim' : 'Route association only');
        }
        unset($package);
        $bodies=$this->q('SELECT body.id,body.code,body.display_sequence,body.status,body.body_model_id,
                model.code AS model_code,model.version AS model_version,model.name AS model_name
                FROM locker_body_modules body LEFT JOIN locker_body_models model ON model.id=body.body_model_id
                WHERE body.locker_id=? ORDER BY body.display_sequence',[$id])->fetchAll(PDO::FETCH_ASSOC);
        $nextBodyPosition=$bodies ? 1+max(array_map(static fn(array $body): int => (int)$body['display_sequence'],$bodies)) : 1;
        return ['locker'=>$locker,'occupancy'=>$occupancy,'unclaimed_custody'=>$unclaimedCustody,'packages'=>$packages,
            'next_package_cursor'=>$more?(string)end($packages)['id']:null,
            'devices'=>$this->q('SELECT id,external_device_id,status,created_at FROM locker_devices WHERE locker_id=? ORDER BY id',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'boards'=>$this->q('SELECT id,board_address,protocol_profile,display_sequence FROM controller_boards WHERE locker_id=? ORDER BY display_sequence',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'ownership'=>$this->q('SELECT generation,state,activated_at,created_at FROM ownership_manifests WHERE locker_id=? ORDER BY generation DESC LIMIT 10',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'bodies'=>$bodies,'next_body_position'=>$nextBodyPosition,
            'modules'=>$this->q('SELECT m.id,m.code,m.body_module_id,m.display_sequence,m.status FROM locker_box_modules m WHERE m.locker_id=? ORDER BY m.body_module_id,m.display_sequence',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'boxes'=>$boxes];
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
        Input::fields($input,['code','module_id','row','column','width_mm','height_mm','depth_mm','max_weight_g','reason']);
        $code=self::moduleCode($input['code']); $module=self::id((string)$input['module_id']);
        $row=self::position($input['row']); $column=self::position($input['column']);
        $sizes=[];
        foreach (['width_mm','height_mm','depth_mm','max_weight_g'] as $field) {
            $value=$input[$field];
            if (!is_string($value) || !ctype_digit($value) || (int)$value<1 || (int)$value>100000) {
                throw new Failure(422,'INVALID_INPUT','Box measurements must be positive whole numbers.');
            }
            $sizes[]=(int)$value;
        }
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$locker,$code,$module,$row,$column,$sizes,$reason) {
            $this->lockLocker($actor,$locker);
            $status=$this->q('SELECT l.status FROM lockers k JOIN locations l ON l.id=k.location_id WHERE k.id=?',[$locker])->fetchColumn();
            if ($status!=='INACTIVE') { throw new Failure(409,'LOCKER_LOCATION_ACTIVE','New boxes require an inactive locker location.'); }
            if (!$this->q('SELECT 1 FROM locker_box_modules WHERE id=? AND locker_id=?',[$module,$locker])->fetchColumn()) {
                throw new Failure(404,'MODULE_NOT_FOUND','Box module not found at this locker.');
            }
            if ($this->q('SELECT 1 FROM compartments WHERE locker_id=? AND code=?',[$locker,$code])->fetchColumn()) {
                throw new Failure(409,'BOX_CODE_EXISTS','Box code already exists at this locker.');
            }
            if ($this->q('SELECT 1 FROM compartments c JOIN locker_box_modules m ON m.id=c.box_module_id
                WHERE m.body_module_id=(SELECT body_module_id FROM locker_box_modules WHERE id=?)
                AND c.display_row=? AND c.display_column=?',[$module,$row,$column])->fetchColumn()) {
                throw new Failure(409,'BOX_POSITION_EXISTS','This body position already has a box.');
            }
            $id=$this->q("INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status,box_module_id,display_row,display_column)
                VALUES (?,?,?,?,?,?,'FROZEN',?,?,?) RETURNING id",[$locker,$code,...$sizes,$module,$row,$column])->fetchColumn();
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
            $row=$this->q('SELECT box_module_id,display_row,display_column FROM compartments WHERE id=? AND locker_id=? FOR UPDATE',[$box,$locker])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'BOX_NOT_FOUND','Box not found at this locker.'); }
            if ($this->q('SELECT 1 FROM compartment_claims WHERE compartment_id=?',[$box])->fetchColumn()
                || $this->q("SELECT 1 FROM locker_sessions WHERE compartment_id=? AND status IN ('READY','OPEN','CLOSED','UNKNOWN','CONFIRMED') LIMIT 1",[$box])->fetchColumn()) {
                throw new Failure(409,'BOX_IN_USE','Reconcile the box before changing its inventory grouping.');
            }
            if ($row['display_row']!==null && $row['display_column']!==null && $this->q('SELECT 1 FROM compartments c
                JOIN locker_box_modules m ON m.id=c.box_module_id
                WHERE m.body_module_id=(SELECT body_module_id FROM locker_box_modules WHERE id=?)
                AND c.id<>? AND c.display_row=? AND c.display_column=?',[$module,$box,$row['display_row'],$row['display_column']])->fetchColumn()) {
                throw new Failure(409,'BOX_POSITION_EXISTS','This body position already has a box.');
            }
            $this->q('UPDATE compartments SET box_module_id=? WHERE id=?',[$module,$box]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'LOCKER_BOX_GROUPED','locker',?,?)",[$actor,$locker,$reason.' [box '.$box.']']);
        });
    }
}
