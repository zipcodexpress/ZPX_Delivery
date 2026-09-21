<?php
declare(strict_types=1);
namespace Zpx\Payments;
use PDO;
use Zpx\Identity\{Service,Secrets,Failure};
final class Wallet {
 private AuthorizeNet $gateway;
 public function __construct(private PDO $db,private Secrets $crypto,?AuthorizeNet $gateway=null){$this->gateway=$gateway ?? new AuthorizeNet();}
 private function q(string $sql,array $args=[]): \PDOStatement {$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 private function authorize(string $user):void { $s=new Service($this->db,$this->crypto);$s->requireRole($user,'CUSTOMER');$s->requireVerified($user);if(!in_array(getenv('APP_ENV'),['development','test'],true)){throw new Failure(503,'PAYMENT_NOT_CONFIGURED','Only sandbox cards are available.');} }
 public function methods(string $user):array {
  $this->authorize($user);$id=$this->q('SELECT provider_profile_id FROM customer_payment_profiles WHERE user_id=?',[$user])->fetchColumn();$items=[];
  if($id){foreach($this->gateway->customerProfile((string)$id)['paymentProfiles'] ?? [] as $p){$card=$p['payment']['creditCard'] ?? [];if(preg_match('/([0-9]{4})$/D',$card['cardNumber'] ?? '',$m)){$items[]=['brand'=>(string)($card['cardType'] ?? 'Card'),'last4'=>$m[1]];}}}
  return ['items'=>$items,'provider'=>'AUTHORIZE_NET_SANDBOX','configured'=>AuthorizeNet::configured()];
 }
 public function manage(string $user):array {
  $this->authorize($user);if(!AuthorizeNet::configured()){throw new Failure(503,'PAYMENT_NOT_CONFIGURED','Sandbox card management is not configured.');}
  $lock='wallet:'.$user;if(!$this->q('SELECT pg_try_advisory_lock(hashtextextended(?,0))',[$lock])->fetchColumn()){throw new Failure(503,'WALLET_BUSY','Card management is being prepared. Try shortly.');}
  try {
   $this->q('INSERT INTO customer_payment_profiles(user_id,merchant_reference) VALUES (?,?) ON CONFLICT(user_id) DO NOTHING',[$user,'ZP'.strtoupper(bin2hex(random_bytes(9)))]);
   $r=$this->q('SELECT * FROM customer_payment_profiles WHERE user_id=?',[$user])->fetch(PDO::FETCH_ASSOC);
   $id=$r['provider_profile_id'];if(!$id){$id=$this->gateway->createProfile($r['merchant_reference']);$this->q('UPDATE customer_payment_profiles SET provider_profile_id=? WHERE user_id=?',[$id,$user]);}
   return ['checkout_url'=>'https://test.authorize.net/customer/manage','checkout_token'=>$this->gateway->profileForm($id)];
  } finally {$this->q('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$lock]);}
 }
 public function history(string $user,string $cursor=''):array {
  $this->authorize($user);if($cursor!==''&&!preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)){throw new Failure(422,'INVALID_INPUT','Invalid cursor.');}
  $args=[$user,getenv('ZPX_ORGANIZATION_ID')?:'0'];$where='';if($cursor!==''){$where=' AND p.id<?';$args[]=$cursor;}
  $rows=$this->q("SELECT p.id,p.status,p.provider,p.provider_reference,p.provider_transaction_id,p.amount_cents,p.created_at,q.currency,s.public_reference,s.id AS shipment_id FROM payments p JOIN shipments s ON s.id=p.shipment_id JOIN pricing_quotes q ON q.id=p.quote_id WHERE s.sender_user_id=? AND s.organization_id=?".$where.' ORDER BY p.id DESC LIMIT 51',$args)->fetchAll(PDO::FETCH_ASSOC);
  $more=count($rows)>50;$rows=array_slice($rows,0,50);$items=array_map(fn($r)=>['payment_id'=>(string)$r['id'],'shipment_id'=>(string)$r['shipment_id'],'shipment_reference'=>$r['public_reference'],'status'=>$r['status'],'provider'=>$r['provider'],'reference'=>$r['provider_reference'],'transaction_id'=>$r['provider_transaction_id'],'amount_cents'=>(int)$r['amount_cents'],'currency'=>$r['currency'],'created_at'=>gmdate('c',strtotime($r['created_at']))],$rows);
  return ['items'=>$items,'next_cursor'=>$more?(string)end($rows)['id']:null];
 }
}
