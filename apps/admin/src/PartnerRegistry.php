<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets};
use Zpx\Infrastructure\Database\Transaction;

/** Registry identities only; no partner row or role grants access to delivery resources. */
final class PartnerRegistry
{
    private const ROLES=['HOST','SITE_OWNER','CARRIER','LOCKER_OWNER','LOCKER_OPERATOR','HUB_OPERATOR'];
    public function __construct(private PDO $db, private Secrets $crypto) {}
    private function q(string $sql,array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function authorize(string $actor): void { (new Access($this->db))->requireNetworkAdmin($actor); }
    private function present(array $row): array
    {
        $roles=$this->q('SELECT role_code FROM network_partner_roles WHERE partner_id=? AND organization_id=? ORDER BY role_code',
            [$row['id'],$this->org()])->fetchAll(PDO::FETCH_COLUMN);
        return ['partner_id'=>(string)$row['id'],'code'=>$row['code'],'display_name'=>$row['display_name'],
            'legal_name'=>$row['legal_name'],'kind'=>$row['kind'],'status'=>$row['status'],
            'version'=>(int)$row['version'],'roles'=>$roles,'created_at'=>$row['created_at']];
    }
    public function list(string $actor,string $cursor=''): array
    {
        $this->authorize($actor);
        if ($cursor!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)) { throw new Failure(422,'INVALID_CURSOR','Invalid partner cursor.'); }
        $args=[$this->org()]; $where='';
        if ($cursor!=='') { $where=' AND id<?'; $args[]=$cursor; }
        $rows=$this->q('SELECT id,code,display_name,legal_name,kind,status,version,created_at FROM network_partners WHERE organization_id=?'
            .$where.' ORDER BY id DESC LIMIT 26',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        return ['items'=>array_map(fn($row)=>$this->present($row),$rows),
            'next_cursor'=>$more?(string)end($rows)['id']:null];
    }
    public function detail(string $actor,string $partner): array
    {
        $this->authorize($actor);
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$partner)) { throw new Failure(422,'INVALID_INPUT','Invalid partner ID.'); }
        $row=$this->q('SELECT id,code,display_name,legal_name,kind,status,version,created_at FROM network_partners WHERE id=? AND organization_id=?',
            [$partner,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(404,'PARTNER_NOT_FOUND','Partner not found.'); }
        $activity=$this->q("SELECT action,created_at FROM audit_events WHERE entity_type='partner' AND entity_id=? ORDER BY id DESC LIMIT 10",[$partner])
            ->fetchAll(PDO::FETCH_ASSOC);
        return ['partner'=>$this->present($row),'activity'=>$activity];
    }
    public function create(string $actor,array $input,string $key): array
    {
        $this->authorize($actor);
        Input::fields($input,['code','display_name','roles','reason'],['legal_name']);
        $code=trim(Input::text($input['code'],3,40));
        if (!preg_match('/^[A-Z][A-Z0-9-]{2,39}$/D',$code) || $code==='ZPX-INTERNAL') {
            throw new Failure(422,'INVALID_INPUT','Use a unique uppercase partner code.');
        }
        $name=Input::text(trim(Input::text($input['display_name'],1,160)),2,160);
        if (array_key_exists('legal_name',$input) && !is_string($input['legal_name'])) {
            throw new Failure(422,'INVALID_INPUT','Legal name must be text.');
        }
        $legal=($input['legal_name']??'')!=='' ? Input::text(trim(Input::text($input['legal_name'],1,160)),2,160) : null;
        $reason=Input::text(trim(Input::text($input['reason'],1,500)),10,500);
        $roles=$input['roles'];
        if (!is_array($roles) || !array_is_list($roles) || !$roles || count($roles)>count(self::ROLES)
            || array_filter($roles,static fn($role)=>!is_string($role) || !in_array($role,self::ROLES,true))
            || count(array_unique($roles))!==count($roles)) {
            throw new Failure(422,'INVALID_INPUT','Choose one or more distinct partner roles.');
        }
        sort($roles,SORT_STRING);
        Input::text($key,16,100);
        return (new Transaction($this->db))->run(function () use ($actor,$code,$name,$legal,$reason,$roles,$key) {
            $scope='partner-create:'.$this->org().':'.$actor;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('partner-create',json_encode([$code,$name,$legal,$roles,$reason],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])
                ->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','Request key was used with different details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['partner-code:'.$this->org().':'.$code]);
            if ($this->q('SELECT id FROM network_partners WHERE organization_id=? AND code=?',[$this->org(),$code])->fetchColumn()) {
                throw new Failure(409,'PARTNER_CODE_EXISTS','Partner code already exists.');
            }
            $id=(string)$this->q("INSERT INTO network_partners(organization_id,code,display_name,legal_name,kind,status,created_by)
                VALUES (?,?,?,?,'EXTERNAL','DRAFT',?) RETURNING id",[$this->org(),$code,$name,$legal,$actor])->fetchColumn();
            foreach ($roles as $role) {
                $this->q('INSERT INTO network_partner_roles(partner_id,organization_id,role_code,created_by) VALUES (?,?,?,?)',
                    [$id,$this->org(),$role,$actor]);
            }
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'PARTNER_DRAFT_CREATED','partner',?,?)",
                [$actor,$id,$reason]);
            $result=$this->detail($actor,$id);
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),201,?,now()+interval '30 days')",
                [$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
