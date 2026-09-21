<?php
declare(strict_types=1);
namespace Zpx\Payments;
use PDO;
use Zpx\Identity\{Failure,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;

final class HostedCheckout
{
    private AuthorizeNet $gateway;
    public function __construct(private PDO $db,private Secrets $crypto,?AuthorizeNet $gateway=null) { $this->gateway=$gateway ?? new AuthorizeNet(); }
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql);$q->execute($args);return $q; }
    private function row(string $user,string $id): array {
        $identity=new Identity($this->db,$this->crypto);$identity->requireRole($user,'CUSTOMER');$identity->requireVerified($user);
        $row=$this->q("SELECT p.*,s.organization_id,s.sender_user_id,s.order_status,s.development_only,q.expires_at AS quote_expires_at,k.id AS package_id FROM payments p JOIN shipments s ON s.id=p.shipment_id JOIN pricing_quotes q ON q.id=p.quote_id JOIN packages k ON k.shipment_id=s.id AND k.sequence_no=1 WHERE p.id=? AND s.sender_user_id=? AND s.organization_id=?",[$id,$user,getenv('ZPX_ORGANIZATION_ID') ?: '0'])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(404,'PAYMENT_NOT_FOUND','Payment not found.'); }
        if (!$row['development_only'] || !in_array(getenv('APP_ENV'),['development','test'],true)) { throw new Failure(503,'PAYMENT_NOT_CONFIGURED','Only sandbox shipping is enabled.'); }
        return $row;
    }
    private function present(array $r): array {
        $body=['payment_id'=>(string)$r['id'],'provider_session_reference'=>$r['provider_reference'],'provider'=>$r['provider'],'status'=>$r['status'],'development_only'=>true];
        if ($r['provider']==='AUTHORIZE_NET_SANDBOX' && $r['status']==='PENDING') {
            $expired=$r['hosted_token_expires_at']!==null && strtotime($r['hosted_token_expires_at'])<=time();
            $body['checkout_expired']=$expired;
            if ($r['hosted_token_ciphertext'] && !$expired) {
                $body['checkout_url']=AuthorizeNet::FORM;$body['checkout_token']=$this->crypto->decrypt($r['hosted_token_ciphertext']);
            }
        }
        return $body;
    }
    public function status(string $user,string $id): array { return $this->present($this->row($user,$id)); }
    public function prepare(string $user,string $id): array {
        $row=$this->row($user,$id);
        if ($row['provider']!=='AUTHORIZE_NET_SANDBOX' || $row['status']!=='PENDING' || $row['hosted_token_ciphertext']) { return $this->present($row); }
        // A session lock protects token issuance without holding a database transaction over HTTP.
        $lock='hosted-checkout:'.$id;
        if (!$this->q('SELECT pg_try_advisory_lock(hashtextextended(?,0))',[$lock])->fetchColumn()) { throw new Failure(503,'CHECKOUT_PREPARING','Checkout is being prepared. Retry shortly.'); }
        try {
            $row=$this->row($user,$id);
            if ($row['hosted_token_ciphertext'] || $row['status']!=='PENDING') { return $this->present($row); }
            if (strtotime($row['quote_expires_at'])<=time()) { throw new Failure(409,'QUOTE_EXPIRED','The quote expired before checkout was prepared.'); }
            $profile=$this->q('SELECT provider_profile_id FROM customer_payment_profiles WHERE user_id=?',[$user])->fetchColumn();
            $token=$this->gateway->hosted((int)$row['amount_cents'],$row['provider_reference'],$profile?:null);
            $this->q("UPDATE payments SET hosted_token_ciphertext=?,hosted_token_expires_at=now()+interval '15 minutes' WHERE id=? AND status='PENDING'",[$this->crypto->encrypt($token),$id]);
            return $this->status($user,$id);
        } finally { $this->q('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$lock]); }
    }
    public function reconcile(string $user,string $id,?string $transactionId=null): array {
        $row=$this->row($user,$id);
        if ($row['provider']!=='AUTHORIZE_NET_SANDBOX') { throw new Failure(409,'WRONG_PROVIDER','This is not an Authorize.net checkout.'); }
        if ($row['status']!=='PENDING') { return $this->present($row); }
        $transactionId=$transactionId ?: $this->gateway->find($row['provider_reference']);
        if ($transactionId===null) { return $this->present($row); }
        $transaction=$this->gateway->transaction($transactionId);
        $this->apply($id,$transaction);
        return $this->status($user,$id);
    }
    private function apply(string $id,array $transaction): void {
        (new Transaction($this->db))->run(function () use ($id,$transaction) {
            $r=$this->q("SELECT p.*,s.organization_id,s.sender_user_id,s.order_status,s.development_only,q.expires_at AS quote_expires_at,k.id AS package_id FROM payments p JOIN shipments s ON s.id=p.shipment_id JOIN pricing_quotes q ON q.id=p.quote_id JOIN packages k ON k.shipment_id=s.id AND k.sequence_no=1 WHERE p.id=? AND p.provider='AUTHORIZE_NET_SANDBOX' AND s.organization_id=? FOR UPDATE OF s,p",[$id,getenv('ZPX_ORGANIZATION_ID') ?: '0'])->fetch(PDO::FETCH_ASSOC);
            if (!$r || !$r['development_only']) { throw new Failure(404,'PAYMENT_NOT_FOUND','Payment not found.'); }
            $transactionId=(string)($transaction['transId'] ?? '');
            if (($transaction['order']['invoiceNumber'] ?? '')!==$r['provider_reference'] || AuthorizeNet::cents($transaction['authAmount'] ?? null)!==(int)$r['amount_cents'] || (isset($transaction['currencyCode']) && $transaction['currencyCode']!=='USD') || ($transaction['transactionType'] ?? '')!=='authCaptureTransaction') { throw new Failure(409,'PAYMENT_MISMATCH','The provider transaction does not match this checkout.'); }
            if ($r['status']==='PAID') {
                if ($r['provider_transaction_id']!==$transactionId) { throw new Failure(409,'PAYMENT_CONFLICT','A different transaction already paid this order.'); }
                return;
            }
            if ($r['status']!=='PENDING' || $r['order_status']!=='DRAFT') { throw new Failure(409,'PAYMENT_CONFLICT','This checkout is not pending.'); }
            if (!in_array($transaction['transactionStatus'] ?? '',['capturedPendingSettlement','settledSuccessfully'],true) || (string)($transaction['responseCode'] ?? '')!=='1') { throw new Failure(409,'PAYMENT_NOT_CAPTURED','The provider has not confirmed a successful capture.'); }
            if (isset($transaction['settleAmount']) && AuthorizeNet::cents($transaction['settleAmount'])!==(int)$r['amount_cents']) { throw new Failure(409,'PAYMENT_MISMATCH','The captured amount does not match the quote.'); }
            $submitted=strtotime($transaction['submitTimeUTC'] ?? '');
            if (!$submitted || $submitted>strtotime($r['quote_expires_at']) || $submitted<strtotime($r['created_at'])-60) { throw new Failure(409,'PAYMENT_REVIEW_REQUIRED','Payment timing requires review; shipping is not enabled.'); }
            $this->q("UPDATE payments SET status='PAID',provider_transaction_id=?,hosted_token_ciphertext=NULL WHERE id=?",[$transactionId,$id]);
            $this->q("UPDATE shipments SET payment_status='PAID',order_status='READY',version=version+1 WHERE id=?",[$r['shipment_id']]);
            $this->q("INSERT INTO payment_events(provider,provider_event_id,payment_id,payload_reference) VALUES ('AUTHORIZE_NET_SANDBOX',?,?,?)",['verified-'.$transactionId,$id,'Verified sandbox transaction; no card details stored']);
            $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,'SANDBOX_PAYMENT_CONFIRMED',?,'{}',now())",[$r['package_id'],Secrets::uuid(),$r['sender_user_id']]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'SANDBOX_PAYMENT_CONFIRMED','payment',?)",[$r['sender_user_id'],$id]);
            (new Outbox($this->db))->append(Secrets::uuid(),'shipment',(string)$r['shipment_id'],'shipping.sandbox_payment_confirmed',['shipment_id'=>(string)$r['shipment_id']]);
        });
    }
    public function webhook(string $raw,string $signature): array {
        AuthorizeNet::verifySignature($raw,$signature);
        try { $event=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new Failure(400,'INVALID_JSON','Invalid notification body.'); }
        if (!is_array($event) || ($event['eventType'] ?? '')!=='net.authorize.payment.authcapture.created' || ($event['payload']['entityName'] ?? '')!=='transaction') { return ['status'=>'IGNORED']; }
        $transaction=$this->gateway->transaction((string)($event['payload']['id'] ?? ''));
        $id=$this->q("SELECT p.id FROM payments p JOIN shipments s ON s.id=p.shipment_id WHERE p.provider='AUTHORIZE_NET_SANDBOX' AND p.provider_reference=? AND s.organization_id=?",[$transaction['order']['invoiceNumber'] ?? '',getenv('ZPX_ORGANIZATION_ID') ?: '0'])->fetchColumn();
        if (!$id) { return ['status'=>'IGNORED']; }
        $this->apply((string)$id,$transaction);return ['status'=>'ACCEPTED'];
    }
}
