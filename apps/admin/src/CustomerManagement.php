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

    public function list(string $actor, string $cursor='',string $search='',string $status=''): array
    {
        $this->authorize($actor);
        if ($cursor!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)) {
            throw new Failure(422,'INVALID_CURSOR','Invalid customer cursor.');
        }
        $args=[$this->org()];
        $cursorWhere='';
        if ($search!=='') { $cursorWhere.=' AND (u.display_name ILIKE ? OR u.id::text=?)'; array_push($args,'%'.$search.'%',$search); }
        if ($status==='RESTRICTED') { $cursorWhere.=' AND EXISTS (SELECT 1 FROM customer_shipping_restrictions sr WHERE sr.user_id=u.id AND sr.revoked_at IS NULL)'; }
        if ($status==='AVAILABLE') { $cursorWhere.=' AND NOT EXISTS (SELECT 1 FROM customer_shipping_restrictions sr WHERE sr.user_id=u.id AND sr.revoked_at IS NULL)'; }
        if ($cursor!=='') { $cursorWhere.=' AND u.id<?'; $args[]=$cursor; }
        $rows=$this->query("SELECT u.id,u.display_name,u.status,u.created_at,
            (SELECT sr.id FROM customer_shipping_restrictions sr WHERE sr.user_id=u.id AND sr.revoked_at IS NULL) AS restriction_id
            FROM users u WHERE u.organization_id=? AND EXISTS(
                SELECT 1 FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id
                WHERE g.user_id=u.id AND g.organization_id=u.organization_id AND r.code='CUSTOMER'
                AND (g.expires_at IS NULL OR g.expires_at>now()))".$cursorWhere.' ORDER BY u.id DESC LIMIT 26',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        $items=array_map(fn($row)=>$this->present($row),$rows);
        return ['items'=>$items,'next_cursor'=>$more?(string)end($rows)['id']:null];
    }

    private function present(array $row): array
    {
        $contacts=$this->query('SELECT kind,value_ciphertext,verified_at FROM user_contacts WHERE user_id=?',[$row['id']])->fetchAll(PDO::FETCH_ASSOC);
        $masked=[]; $verified=[];
        foreach ($contacts as $contact) {
            $kind=$contact['kind'];
            $value=$this->crypto->decrypt($contact['value_ciphertext']);
            $masked[$kind]=$kind==='EMAIL' ? self::maskEmail($value) : self::maskPhone($value);
            $verified[$kind]=$contact['verified_at']!==null;
        }
        return ['user_id'=>(string)$row['id'],'name'=>$row['display_name'],'status'=>$row['status'],
            'created_at'=>$row['created_at'],'shipping_restricted'=>$row['restriction_id']!==null,
            'restriction_id'=>$row['restriction_id']===null?null:(string)$row['restriction_id'],
            'masked_email'=>$masked['EMAIL']??null,'masked_phone'=>$masked['PHONE']??null,
            'email_verified'=>$verified['EMAIL']??false,'phone_verified'=>$verified['PHONE']??false];
    }

    public function detail(string $actor, string $user): array
    {
        $this->authorize($actor);
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$user)) { throw new Failure(422,'INVALID_INPUT','Invalid customer ID.'); }
        $row=$this->query("SELECT u.id,u.display_name,u.status,u.created_at,
            (SELECT sr.id FROM customer_shipping_restrictions sr WHERE sr.user_id=u.id AND sr.revoked_at IS NULL) AS restriction_id
            FROM users u WHERE u.id=? AND u.organization_id=? AND EXISTS(
                SELECT 1 FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id
                WHERE g.user_id=u.id AND g.organization_id=u.organization_id AND r.code='CUSTOMER'
                AND (g.expires_at IS NULL OR g.expires_at>now()))",[$user,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(404,'CUSTOMER_NOT_FOUND','Customer not found.'); }
        $shipments=$this->query("SELECT s.id,s.public_reference,s.order_status,s.payment_status,s.created_at,
            p.state AS package_state,CASE WHEN s.sender_user_id=? THEN 'SENDER' ELSE 'RECIPIENT' END AS relationship
            FROM shipments s LEFT JOIN packages p ON p.shipment_id=s.id AND p.sequence_no=1
            WHERE s.organization_id=? AND (s.sender_user_id=? OR EXISTS(
                SELECT 1 FROM shipment_parties sp WHERE sp.shipment_id=s.id AND sp.party_role='RECIPIENT' AND sp.user_id=?))
            ORDER BY s.id DESC LIMIT 25",[$user,$this->org(),$user,$user])->fetchAll(PDO::FETCH_ASSOC);
        $activity=$this->query("SELECT action,created_at FROM audit_events WHERE entity_type='customer' AND entity_id=? ORDER BY id DESC LIMIT 10",[$user])->fetchAll(PDO::FETCH_ASSOC);
        $payments=$this->query("SELECT p.id,p.shipment_id,p.amount_cents,p.status,p.provider,p.created_at,s.public_reference
            FROM payments p JOIN shipments s ON s.id=p.shipment_id WHERE s.sender_user_id=? AND s.organization_id=?
            ORDER BY p.id DESC LIMIT 25",[$user,$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        $totals=$this->query("SELECT
            (SELECT count(*) FROM shipments s WHERE s.sender_user_id=? AND s.organization_id=?) AS shipment_count,
            (SELECT count(*) FROM shipments s WHERE s.sender_user_id=? AND s.organization_id=? AND s.payment_status='PAID') AS paid_shipment_count,
            (SELECT count(*) FROM shipment_parties sp JOIN shipments s ON s.id=sp.shipment_id
                WHERE sp.party_role='RECIPIENT' AND sp.user_id=? AND s.organization_id=?) AS received_count",
            [$user,$this->org(),$user,$this->org(),$user,$this->org()])->fetch(PDO::FETCH_ASSOC);
        $paymentTotals=$this->query("SELECT
            (SELECT COALESCE(sum(p.amount_cents),0) FROM payments p JOIN shipments s ON s.id=p.shipment_id
                WHERE s.sender_user_id=? AND s.organization_id=? AND p.status='PAID') AS captured_cents,
            (SELECT COALESCE(sum(r.amount_cents),0) FROM refunds r JOIN payments p ON p.id=r.payment_id
                JOIN shipments s ON s.id=p.shipment_id WHERE s.sender_user_id=? AND s.organization_id=? AND r.status='SUCCEEDED') AS refunded_cents",
            [$user,$this->org(),$user,$this->org()])->fetch(PDO::FETCH_ASSOC);
        return ['customer'=>$this->present($row),'shipments'=>array_map(static fn($s)=>[
            'shipment_id'=>(string)$s['id'],'public_reference'=>$s['public_reference'],'order_status'=>$s['order_status'],
            'payment_status'=>$s['payment_status'],'package_state'=>$s['package_state'],'relationship'=>$s['relationship'],
            'created_at'=>$s['created_at'],
        ],$shipments),'activity'=>$activity,'payments'=>$payments,'totals'=>$totals,'payment_totals'=>$paymentTotals];
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
