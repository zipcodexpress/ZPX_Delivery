<?php
declare(strict_types=1);
use Zpx\Payments\{AuthorizeNet,HostedCheckout};
use Zpx\Identity\Secrets;
putenv('AUTHORIZE_NET_ENVIRONMENT=sandbox');putenv('AUTHORIZE_NET_API_LOGIN_ID=synthetic');putenv('AUTHORIZE_NET_TRANSACTION_KEY=synthetic');putenv('AUTHORIZE_NET_SIGNATURE_KEY='.str_repeat('a1',64));
check(AuthorizeNet::cents('6.01')===601 && AuthorizeNet::dollars(601)==='6.01','provider amount conversion is exact');
failsIdentity(fn()=>AuthorizeNet::cents('6.001'),409,'fractional cent rejected');
$raw='{"eventType":"ignored"}';$signature='sha512='.hash_hmac('sha512',$raw,hex2bin(str_repeat('a1',64)));
AuthorizeNet::verifySignature($raw,$signature);
failsIdentity(fn()=>AuthorizeNet::verifySignature($raw.' ',$signature),401,'signature covers raw bytes');
$calls=0;$transaction=[];
$gateway=new AuthorizeNet(function ($request) use (&$calls,&$transaction) {
 $calls++;$method=array_key_first($request);$body=$request[$method];
 check($body['merchantAuthentication']['transactionKey']==='synthetic','gateway supplies configured credentials');
 if ($method==='getHostedPaymentPageRequest') { check($body['transactionRequest']['amount']==='6.00' && $body['transactionRequest']['transactionType']==='authCaptureTransaction','hosted request uses stored quote');return ['messages'=>['resultCode'=>'Ok'],'token'=>'synthetic-hosted-token-123456789']; }
 if ($method==='getTransactionDetailsRequest') { return ['messages'=>['resultCode'=>'Ok'],'transaction'=>$transaction]; }
 if ($method==='getUnsettledTransactionListRequest') { return ['messages'=>['resultCode'=>'Ok'],'transactions'=>[['invoiceNumber'=>$transaction['order']['invoiceNumber'],'transId'=>$transaction['transId']]]]; }
 return ['messages'=>['resultCode'=>'Ok']];
});
// Create a local pending fixture, then select the sandbox provider without any network.
$draft=$shipping->create($sender,$input,Secrets::uuid());$id=$draft['shipment_id'];
$quote=$shipping->quote($sender,$id,['service_level'=>'STANDARD'],Secrets::uuid(),'"0"');
putenv('PAYMENT_PROVIDER=UNSUPPORTED');
failsIdentity(fn()=>$shipping->payment($sender,$id,['quote_id'=>$quote['quote_id']],Secrets::uuid(),'"0"'),503,'unknown provider never silently falls back to simulated success');
putenv('PAYMENT_PROVIDER=LOCAL_TEST');
$pay=$shipping->payment($sender,$id,['quote_id'=>$quote['quote_id']],Secrets::uuid(),'"0"');$pid=$pay['payment_id'];$invoice='ZP'.strtoupper(bin2hex(random_bytes(9)));
$q=$runtime->prepare("UPDATE payments SET provider='AUTHORIZE_NET_SANDBOX',provider_reference=? WHERE id=?");$q->execute([$invoice,$pid]);
$checkout=new HostedCheckout($runtime,$crypto,$gateway);
$session=$checkout->prepare($sender,$pid);check($session['checkout_url']===AuthorizeNet::FORM && strlen($session['checkout_token'])>20,'sandbox form prepared');
check($checkout->prepare($sender,$pid)===$session && $calls===1,'hosted token issuance is reused');
$encrypted=$runtime->query("SELECT hosted_token_ciphertext FROM payments WHERE id=$pid")->fetchColumn();check(!str_contains($encrypted,'synthetic-hosted') && $crypto->decrypt($encrypted)===$session['checkout_token'],'hosted token encrypted at rest');
failsIdentity(fn()=>$checkout->status($recipient,$pid),404,'another customer cannot retrieve hosted token');
failsIdentity(fn()=>$shipping->confirmPayment($sender,$pid,['outcome'=>'SUCCEEDED'],Secrets::uuid()),409,'local simulator cannot confirm sandbox payment');
$transaction=['transId'=>'9912345678','order'=>['invoiceNumber'=>$invoice],'authAmount'=>'6.00','settleAmount'=>'6.00','transactionType'=>'authCaptureTransaction','responseCode'=>1,'transactionStatus'=>'capturedPendingSettlement','submitTimeUTC'=>gmdate('Y-m-d\TH:i:s\Z')];
$transaction['authAmount']='6.01';failsIdentity(fn()=>$checkout->reconcile($sender,$pid,'9912345678'),409,'provider amount mismatch fails closed');
$transaction['authAmount']='6.00';$transaction['order']['invoiceNumber']='OTHER';failsIdentity(fn()=>$checkout->reconcile($sender,$pid,'9912345678'),409,'another invoice cannot pay order');
$transaction['order']['invoiceNumber']=$invoice;$transaction['transactionStatus']='declined';failsIdentity(fn()=>$checkout->reconcile($sender,$pid,'9912345678'),409,'declined capture does not enable label');
$transaction['transactionStatus']='capturedPendingSettlement';
check($checkout->reconcile($sender,$pid)['status']==='PAID','server reconciliation confirms sandbox capture');
check(!isset($checkout->status($sender,$pid)['checkout_token']),'paid response no longer exposes token');
$event=json_encode(['eventType'=>'net.authorize.payment.authcapture.created','payload'=>['entityName'=>'transaction','id'=>'9912345678']]);$sig='sha512='.hash_hmac('sha512',$event,hex2bin(str_repeat('a1',64)));
check($checkout->webhook($event,$sig)['status']==='ACCEPTED' && $checkout->webhook($event,$sig)['status']==='ACCEPTED','signed duplicate webhook is idempotent');
check((int)$runtime->query("SELECT count(*) FROM payment_events WHERE payment_id=$pid")->fetchColumn()===1,'capture recorded exactly once');
check($shipping->get($sender,$id)['package_state']==='CREATED','payment never advances physical custody');
putenv('APP_ENV=production');failsIdentity(fn()=>$gateway->authenticate(),503,'gateway forbids production');putenv('APP_ENV=test');
// Mail workers use injected delivery in tests; no SMTP network calls.
$c=$identity->challenge(['kind'=>'EMAIL','contact_value'=>$senderInput['email'],'purpose'=>'REGISTER']);
$q=$runtime->prepare("UPDATE outbox_events SET payload=jsonb_set(payload,'{delivery}','\"SMTP\"') WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) RETURNING id");$q->execute([$c['challenge_id']]);$outbox=(string)$q->fetchColumn();
$attempt=0;$worker=new Zpx\Mail\Worker($runtime,$crypto,function($message,$event) use (&$attempt) { $attempt++;check(isset($message['to'],$message['code']),'worker decrypts verification payload');if ($attempt===1) { throw new RuntimeException('synthetic failure'); } });
check($worker->once(),'mail job claimed');check($runtime->query("SELECT delivery_status FROM outbox_events WHERE id=$outbox")->fetchColumn()==='RETRY','delivery failure retained for retry');
$runtime->exec("UPDATE outbox_events SET next_attempt_at=now() WHERE id=$outbox");check($worker->once(),'mail retry claimed');check($runtime->query("SELECT delivery_status FROM outbox_events WHERE id=$outbox")->fetchColumn()==='SENT','SMTP acceptance persisted separately from queueing');check(!$worker->once(),'sent and local-only messages not redelivered');
echo "Sandbox payment and mail delivery tests passed (injected transports; no external messages or charges).\n";

$history=$shipping->paymentHistory($admin,$id);
check(count($history['items'])===1 && $history['items'][0]['transaction_id']==='9912345678' && !str_contains(json_encode($history),'checkout_token'),'operator payment history omits hosted tokens');
failsIdentity(fn()=>$shipping->paymentHistory($recipient,$id),404,'customer denied operator payment history');
failsIdentity(fn()=>$shipping->paymentHistory($hubStaff,$id),404,'unassigned hub staff denied payment history');
$runtime->exec("UPDATE outbox_events SET delivery_status='PENDING',published_at=NULL,next_attempt_at=now() WHERE id=$outbox");
$q=$runtime->prepare("UPDATE verification_challenges SET expires_at=now()-interval '1 second' WHERE public_id=?");$q->execute([$c['challenge_id']]);
check($worker->once() && $attempt===2,'expired verification skipped without sending');
check($runtime->query("SELECT delivery_status FROM outbox_events WHERE id=$outbox")->fetchColumn()==='EXPIRED','expired delivery recorded');
$runtime->exec("UPDATE outbox_events SET delivery_status='RETRY',published_at=NULL,attempts=5,next_attempt_at=now(),locked_until=now()-interval '1 second' WHERE id=$outbox");
check($worker->once() && $attempt===2,'exhausted crashed lease does not send a sixth message');
check($runtime->query("SELECT delivery_status FROM outbox_events WHERE id=$outbox")->fetchColumn()==='FAILED','exhausted crashed job becomes terminal failure');
[$response,$body]=identityHttp('POST',$base.'/integrations/payments/webhook',[]);check($response->getCode()===401,'unsigned HTTP webhook rejected');
[$response,$body]=identityHttp('POST',$base.'/payments/'.$pid.'/reconcile',[]);check($response->getCode()===401,'anonymous reconciliation rejected');
