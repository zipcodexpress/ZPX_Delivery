<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure, Input, Secrets};
use Zpx\Infrastructure\Database\Transaction;

final class CustomerManagement
{
    public function __construct(private PDO $db, private Secrets $crypto) {}

    private function query(string $sql, array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function authorize(string $actor): void { (new Access($this->db))->requireNetworkAdmin($actor); }

    public function list(string $actor, string $cursor=''): array
    {
        $this->authorize($actor);
        if ($cursor!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)) {
            throw new Failure(422,'INVALID_CURSOR','Invalid customer cursor.');
        }
        $args=[$this->org()];
        $cursorWhere='';
        if ($cursor!=='') { $cursorWhere=' AND u.id<?'; $args[]=$cursor; }
        $rows=$this->query("SELECT u.id,u.display_name,u.status,u.created_at,
            (SELECT sr.id FROM customer_shipping_restrictions sr WHERE sr.user_id=u.id AND sr.revoked_at IS NULL) AS restriction_id
            FROM users u WHERE u.organization_id=? AND EXISTS(
                SELECT 1 FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id
                WHERE g.user_id=u.id AND g.organization_id=u.organization_id AND r.code='CUSTOMER'
                AND (g.expires_at IS NULL OR g.expires_at>now()))".$cursorWhere.' ORDER BY u.id DESC LIMIT 26',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        $items=[];
        foreach ($rows as $row) {
            $contacts=$this->query('SELECT kind,value_ciphertext,verified_at FROM user_contacts WHERE user_id=?',[$row['id']])->fetchAll(PDO::FETCH_ASSOC);
            $masked=[]; $verified=[];
            foreach ($contacts as $contact) {
                $kind=$contact['kind'];
                $value=$this->crypto->decrypt($contact['value_ciphertext']);
                $masked[$kind]=$kind==='EMAIL' ? self::maskEmail($value) : self::maskPhone($value);
                $verified[$kind]=$contact['verified_at']!==null;
            }
            $items[]=['user_id'=>(string)$row['id'],'name'=>$row['display_name'],'status'=>$row['status'],
                'created_at'=>$row['created_at'],'shipping_restricted'=>$row['restriction_id']!==null,
                'restriction_id'=>$row['restriction_id']===null?null:(string)$row['restriction_id'],
                'masked_email'=>$masked['EMAIL']??null,'masked_phone'=>$masked['PHONE']??null,
                'email_verified'=>$verified['EMAIL']??false,'phone_verified'=>$verified['PHONE']??false];
        }
        return ['items'=>$items,'next_cursor'=>$more?(string)end($rows)['id']:null];
    }

    private static function maskEmail(string $email): string
    {
        $parts=explode('@',$email,2);
        return substr($parts[0],0,1).'***@'.($parts[1]??'');
    }
    private static function maskPhone(string $phone): string
    { return '***'.substr($phone,-4); }

    public function restrict(string $actor, string $user, mixed $reason, string $key): array
    { return $this->change($actor,$user,$reason,$key,'restrict'); }
    public function revoke(string $actor, string $user, string $restriction, mixed $reason, string $key): array
    { return $this->change($actor,$user,$reason,$key,'revoke',$restriction); }

    private function change(string $actor, string $user, mixed $reason, string $key, string $action, string $restriction=''): array
    {
        $this->authorize($actor);
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$user)) { throw new Failure(422,'INVALID_INPUT','Invalid customer ID.'); }
        if ($action==='revoke' && !preg_match('/^[1-9][0-9]{0,17}$/D',$restriction)) { throw new Failure(422,'INVALID_INPUT','Invalid restriction ID.'); }
        $reason=Input::text(trim(Input::text($reason,1,500)),10,500);
        Input::text($key,16,100);
        return (new Transaction($this->db))->run(function () use ($actor,$user,$reason,$key,$action,$restriction) {
            $scope='customer-restriction:'.$this->org().':'.$actor.':'.$user.':'.$action;
            $this->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('customer-restriction',json_encode([$user,$restriction,$reason,$action],JSON_THROW_ON_ERROR));
            $saved=$this->query('SELECT encode(payload_hash,\'hex\') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?',[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','Request key was used with different details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $target=$this->query("SELECT u.id FROM users u WHERE u.id=? AND u.organization_id=? AND u.status='ACTIVE'
                AND EXISTS(SELECT 1 FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id WHERE g.user_id=u.id
                    AND g.organization_id=u.organization_id AND r.code='CUSTOMER' AND (g.expires_at IS NULL OR g.expires_at>now()))
                FOR UPDATE OF u",[$user,$this->org()])->fetchColumn();
            if (!$target) { throw new Failure(404,'CUSTOMER_NOT_FOUND','Customer not found.'); }
            $current=$this->query('SELECT id FROM customer_shipping_restrictions WHERE user_id=? AND organization_id=? AND revoked_at IS NULL FOR UPDATE',[$user,$this->org()])->fetchColumn();
            if ($action==='restrict') {
                if ($current) { throw new Failure(409,'ALREADY_RESTRICTED','Shipping is already restricted.'); }
                $id=$this->query('INSERT INTO customer_shipping_restrictions(organization_id,user_id,reason,created_by) VALUES (?,?,?,?) RETURNING id',[$this->org(),$user,$reason,$actor])->fetchColumn();
                $restricted=true;
            } else {
                if (!$current || (string)$current!==$restriction) { throw new Failure(409,'RESTRICTION_CHANGED','Shipping restriction changed. Refresh before revoking.'); }
                $this->query('UPDATE customer_shipping_restrictions SET revoked_by=?,revoked_at=now(),revoke_reason=? WHERE id=?',[$actor,$reason,$current]);
                $id=$current; $restricted=false;
            }
            $this->query('INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,?,?,?,?)',
                [$actor,$restricted?'CUSTOMER_SHIPPING_RESTRICTED':'CUSTOMER_SHIPPING_RESTORED','customer',(string)$user,$reason]);
            $result=['user_id'=>$user,'restriction_id'=>(string)$id,'shipping_restricted'=>$restricted];
            $this->query("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",
                [$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
