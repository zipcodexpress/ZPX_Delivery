<?php
declare(strict_types=1);
use Zpx\Shipping\Service as Shipping;
use Zpx\Identity\{Service,Secrets};

$seed=new Zpx\Development\Seed($runtime,'ship-'.bin2hex(random_bytes(4)));
$seeded=(new Zpx\Infrastructure\Database\Transaction($runtime))->run(fn()=>$seed->run(json_decode(file_get_contents(dirname(__DIR__).'/fixtures/pilot.json'),true)));
$shippingOrg=$seeded['organization_id']; putenv('ZPX_ORGANIZATION_ID='.$shippingOrg); putenv('APP_ENV=test');
$shipping=new Shipping($runtime,$crypto); $identity=new Service($runtime,$crypto);
$senderInput=$registration; $senderInput['email']='sender.shipping@example.invalid'; $senderInput['phone']='+12025550111';
$recipientInput=$registration; $recipientInput['email']='recipient.shipping@example.invalid'; $recipientInput['phone']='+12025550112';
$sender=$identity->register($senderInput)['user_id']; $recipient=$identity->register($recipientInput)['user_id'];
$sites=$shipping->locations()['items']; check(count($sites)===20 && $sites[0]['development_only'] && !$sites[0]['eligible'],'synthetic sites are draft-only, not commissioned shipping locations');
$input=['origin_location_id'=>$sites[0]['id'],'destination_location_id'=>$sites[1]['id'],'service_level'=>'STANDARD','recipient'=>['name'=>'Synthetic Recipient','email'=>$recipientInput['email'],'phone'=>$recipientInput['phone']],'package'=>['width_mm'=>100,'height_mm'=>100,'depth_mm'=>100,'weight_g'=>500]];
failsIdentity(fn()=>$shipping->create($sender,$input,Secrets::uuid()),403,'unverified sender cannot create shipment');
foreach ([$senderInput,$recipientInput] as $person) {
 foreach (['EMAIL'=>'email','PHONE'=>'phone'] as $kind=>$field) {
  $c=$identity->challenge(['kind'=>$kind,'contact_value'=>$person[$field],'purpose'=>'REGISTER']);
  $q=$runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'"); $q->execute([$c['challenge_id']]);
  $message=json_decode($crypto->decrypt(json_decode($q->fetchColumn(),true)['encrypted_message']),true);
  $identity->verify(['challenge_id'=>$c['challenge_id'],'code'=>$message['code']]);
 }
}
$key=Secrets::uuid(); $shipment=$shipping->create($sender,$input,$key); $sid=$shipment['shipment_id'];
check($shipment['order_status']==='DRAFT' && $shipment['payment_status']==='UNPAID' && $shipment['package_state']==='CREATED','draft preserves unpaid sender custody');
$reordered=array_reverse($input,true); $again=$shipping->create($sender,$reordered,$key);
check($again['shipment_id']===$sid,'canonical payload retry creates exactly one shipment');
$changed=$input; $changed['package']['weight_g']=501;
failsIdentity(fn()=>$shipping->create($sender,$changed,$key),409,'changed payload cannot reuse request key');
$large=$input; $large['package']['width_mm']=99999;
failsIdentity(fn()=>$shipping->create($sender,$large,Secrets::uuid()),422,'parcel must fit both declared site compartment sizes');
$invalid=$input; $invalid['origin_location_id']=$input['destination_location_id'];
failsIdentity(fn()=>$shipping->create($sender,$invalid,Secrets::uuid()),422,'same-site route rejected');
failsIdentity(fn()=>$shipping->create($sender,$input+['sender_user_id'=>$recipient],Secrets::uuid()),422,'sender override injection rejected');
check(count($shipping->list($sender,'sending')['items'])===1,'sender list contains own draft');
check(count($shipping->list($recipient,'receiving')['items'])===0,'matching verified contacts alone do not grant recipient access');
failsIdentity(fn()=>$shipping->get($recipient,$sid),404,'unclaimed recipient cannot read shipment');
$stored=$runtime->query("SELECT contact_encrypted FROM shipment_parties WHERE shipment_id=$sid")->fetchColumn();
check(!str_contains($stored,$recipientInput['email']) && json_decode($crypto->decrypt($stored),true)['email']===$recipientInput['email'],'recipient snapshot encrypted at rest');
$quote=$shipping->quote($sender,$sid,['service_level'=>'STANDARD'],Secrets::uuid(),'"0"');
check($quote['development_only'] && $quote['amount_cents']===600 && strtotime($quote['expires_at'])>time(),'versioned expiring test quote uses server parcel weight');
failsIdentity(fn()=>$shipping->quote($sender,$sid,['service_level'=>'STANDARD'],Secrets::uuid(),'"99"'),409,'stale quote precondition rejected');
putenv('APP_ENV=production');
failsIdentity(fn()=>$shipping->quote($sender,$sid,['service_level'=>'STANDARD'],Secrets::uuid(),'"0"'),503,'demo rates cannot be used in production');
putenv('APP_ENV=test');
$claim=$shipping->claimChallenge($recipient,['public_reference'=>$shipment['public_reference']],Secrets::uuid());
$q=$runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");$q->execute([$claim['challenge_id']]);
$message=json_decode($crypto->decrypt(json_decode($q->fetchColumn(),true)['encrypted_message']),true);
failsIdentity(fn()=>$shipping->claim($sender,['challenge_id'=>$claim['challenge_id'],'code'=>$message['code']],Secrets::uuid()),400,'claim is bound to authenticated recipient');
failsIdentity(fn()=>$identity->verify(['challenge_id'=>$claim['challenge_id'],'code'=>$message['code']]),400,'registration proof cannot consume recipient claim');
failsIdentity(fn()=>$shipping->claim($recipient,['challenge_id'=>$claim['challenge_id'],'code'=>'000000'],Secrets::uuid()),400,'wrong recipient claim code rejected');
$q=$runtime->prepare('SELECT attempts FROM verification_challenges WHERE public_id=?');$q->execute([$claim['challenge_id']]);check((int)$q->fetchColumn()===1,'failed claim attempts persist');
$claimKey=Secrets::uuid();$claimInput=['challenge_id'=>$claim['challenge_id'],'code'=>$message['code']];
$claimed=$shipping->claim($recipient,$claimInput,$claimKey);
check($claimed['shipment_id']===$sid && $claimed['relationship']==='RECIPIENT','fresh shipment proof enables receiving access');
check($shipping->claim($recipient,$claimInput,$claimKey)['shipment_id']===$sid,'claim response transport retry is idempotent');
failsIdentity(fn()=>$shipping->claim($recipient,$claimInput,Secrets::uuid()),400,'claim proof is single-use across distinct operations');
check(count($shipping->list($recipient,'receiving')['items'])===1,'claimed parcel appears in receiving');
failsIdentity(fn()=>$shipping->cancel($recipient,$sid,['reason'=>'Not mine'],Secrets::uuid(),'"0"'),403,'recipient cannot cancel sender order');
// The same customer can send: no recipient role or account elevation.
$reverse=$input;$reverse['recipient']=['name'=>'Original Sender','email'=>$senderInput['email'],'phone'=>$senderInput['phone']];
check($shipping->create($recipient,$reverse,Secrets::uuid())['relationship']==='SENDER','one customer account can both send and receive');
$unknown=$shipping->claimChallenge($sender,['public_reference'=>'UNKNOWN'],Secrets::uuid());
check(array_keys($unknown)===array_keys($claim) && $unknown['delivery_status']===$claim['delivery_status'],'unknown shipment challenge is neutral');
// Staff access respects scope and expired grants; no universal hub role access.
$admin=(string)$runtime->query("SELECT id FROM users WHERE organization_id=$shippingOrg AND external_auth_id LIKE '%:ADMIN'")->fetchColumn();
$hubStaff=(string)$runtime->query("SELECT id FROM users WHERE organization_id=$shippingOrg AND external_auth_id LIKE '%:HUB-STAFF'")->fetchColumn();
check($shipping->get($admin,$sid,'operations')['shipment_id']===$sid,'network administrator sees shipment');
failsIdentity(fn()=>$shipping->get($hubStaff,$sid,'operations'),404,'hub staff cannot read parcel outside their hub');
failsIdentity(fn()=>$shipping->get($recipient,$sid,'operations'),404,'customer cannot use operations access');
$runtime->exec("UPDATE scoped_role_grants SET expires_at=now()-interval '1 minute' WHERE user_id=$admin");
failsIdentity(fn()=>$shipping->get($admin,$sid,'operations'),404,'expired staff grant immediately removes shipment access');
$runtime->exec("UPDATE scoped_role_grants SET expires_at=NULL,location_id=".$sites[2]['id']." WHERE user_id=$admin");
failsIdentity(fn()=>$shipping->get($admin,$sid,'operations'),404,'site-restricted administrator cannot see unrelated shipment');
$runtime->exec("UPDATE scoped_role_grants SET location_id=NULL WHERE user_id=$admin");
$oldOrg=getenv('ZPX_ORGANIZATION_ID');putenv('ZPX_ORGANIZATION_ID='.$identityOrg);
failsIdentity(fn()=>$shipping->get($admin,$sid,'operations'),404,'cross-organization shipment hidden');putenv('ZPX_ORGANIZATION_ID='.$oldOrg);
$cancelKey=Secrets::uuid(); $cancelled=$shipping->cancel($sender,$sid,['reason'=>'Test cancellation'],$cancelKey,'"0"');
check($shipping->cancel($sender,$sid,['reason'=>'Test cancellation'],$cancelKey,'"0"')['operation_id']===$cancelled['operation_id'],'cancel replay resolved before stale version check');
check($shipping->get($sender,$sid)['order_status']==='CANCELLED' && $shipping->get($sender,$sid)['version']===1,'cancel advances order version once');
check((int)$runtime->query("SELECT count(*) FROM package_events WHERE package_id=".$shipment['package_id']." AND event_type='SHIPMENT_CANCELLED'")->fetchColumn()===1,'duplicate cancel has one event');
failsIdentity(fn()=>$shipping->quote($sender,$sid,['service_level'=>'STANDARD'],Secrets::uuid(),'"1"'),409,'cancelled shipment cannot be quoted');
check($shipping->get($recipient,$sid)['package_state']==='CREATED','cancellation does not fabricate a physical movement');
// Route-level checks include anonymous access, cookie CSRF and actor attribution.
[$response,$body]=identityHttp('GET',$base.'/shipments');check($response->getCode()===401,'anonymous shipment listing denied');
[$response,$body]=identityHttp('POST',$base.'/auth/login',['email'=>$senderInput['email'],'password'=>$senderInput['password'],'client_kind'=>'BROWSER']);
preg_match('/zpx_delivery_session=([a-f0-9]{64})/',$response->getHeader('Set-Cookie'),$matches);$shipCookies=['zpx_delivery_session'=>$matches[1]];$shipCsrf=$body['csrf_token'];
[$response,$body]=identityHttp('POST',$base.'/shipments',$input,['idempotency-key'=>Secrets::uuid()],$shipCookies);check($response->getCode()===403 && $body['code']==='CSRF_REJECTED','shipment mutation requires CSRF');
[$response,$body]=identityHttp('GET',$base.'/operations/shipments',[],[],$shipCookies);check($response->getCode()===403,'customer denied operations HTTP listing');
[$response,$body]=identityHttp('POST',$base.'/shipments',$input,['idempotency-key'=>Secrets::uuid(),'x-csrf-token'=>$shipCsrf],$shipCookies);
check($response->getCode()===201 && isset($body['shipment_id']),'real ThinkPHP shipment creation route works');
[$response,$body]=identityHttp('GET',$base.'/shipments/'.$sid,[],[],$shipCookies);check($response->getCode()===200 && $body['shipment_id']===$sid,'parameterized ThinkPHP shipment route works');
check(count($shipping->tracking($sender,$sid,'customer')['milestones'])===4,'tracking contains only actual committed events');
echo "Shipping planning and recipient access integration passed. No payments or physical custody fabricated.\n";
// Local checkout exercises payment/label eligibility without a live provider.
$paidDraft=$shipping->create($sender,$input,Secrets::uuid()); $paidId=$paidDraft['shipment_id'];
failsIdentity(fn()=>$shipping->label($sender,$paidDraft['package_id'],Secrets::uuid()),409,'unpaid parcel cannot obtain a label');
$runtime->exec("UPDATE shipments SET development_only=false WHERE id=$paidId");
$q=$shipping->quote($sender,$paidId,['service_level'=>'STANDARD'],Secrets::uuid(),'"0"');
check($shipping->get($sender,$paidId)['development_only'],'quoting existing synthetic draft adds explicit test marker');
$paymentKey=Secrets::uuid();$payment=$shipping->payment($sender,$paidId,['quote_id'=>$q['quote_id']],$paymentKey,'"0"');
check($shipping->payment($sender,$paidId,['quote_id'=>$q['quote_id']],$paymentKey,'"0"')['payment_id']===$payment['payment_id'],'checkout creation retry returns same pending payment');
failsIdentity(fn()=>$shipping->payment($sender,$paidId,['quote_id'=>$q['quote_id']],Secrets::uuid(),'"1"'),409,'second pending checkout rejected');
failsIdentity(fn()=>$shipping->confirmPayment($recipient,$payment['payment_id'],['outcome'=>'SUCCEEDED'],Secrets::uuid()),404,'another customer cannot confirm payment');
putenv('APP_ENV=production');failsIdentity(fn()=>$shipping->confirmPayment($sender,$payment['payment_id'],['outcome'=>'SUCCEEDED'],Secrets::uuid()),503,'test adapter refuses production');putenv('APP_ENV=test');
$confirmKey=Secrets::uuid();$confirmed=$shipping->confirmPayment($sender,$payment['payment_id'],['outcome'=>'SUCCEEDED'],$confirmKey);
check($confirmed['status']==='PAID' && $confirmed['development_only'],'local adapter marks payment explicitly as a test');
check($shipping->confirmPayment($sender,$payment['payment_id'],['outcome'=>'SUCCEEDED'],$confirmKey)['payment_id']===$payment['payment_id'],'confirmation retry produces one financial event');
check((int)$runtime->query('SELECT count(*) FROM payment_events WHERE payment_id='.$payment['payment_id'])->fetchColumn()===1,'only one payment event recorded');
check($shipping->get($sender,$paidId)['package_state']==='CREATED','payment does not change physical custody');
$labelKey=Secrets::uuid();$label=$shipping->label($sender,$paidDraft['package_id'],$labelKey);$again=$shipping->label($sender,$paidDraft['package_id'],Secrets::uuid());
check($label['si']===$again['si'] && $label['label_payload']===$again['label_payload'],'reprint preserves stable SI and active QR identity');
check(preg_match('/^ZPX-[A-Z0-9-]+-[A-Z2-7]{20}$/D',$label['si'])===1 && preg_match('/^ZPX1:L:[A-Za-z0-9_-]{24}$/D',$label['label_payload'])===1,'SI and label token meet canonical entropy formats');
$before=(int)$runtime->query('SELECT count(*) FROM label_print_jobs WHERE label_id='.$label['label_id'])->fetchColumn();$shipping->label($sender,$paidDraft['package_id'],$labelKey);
check((int)$runtime->query('SELECT count(*) FROM label_print_jobs WHERE label_id='.$label['label_id'])->fetchColumn()===$before,'label retry does not duplicate print request audit');
$pdf=$shipping->pdf($sender,$paidDraft['package_id']);check(str_starts_with($pdf,'%PDF-'),'label is an actual generated PDF');
failsIdentity(fn()=>$shipping->pdf($recipient,$paidDraft['package_id']),404,'another customer cannot download label PDF');
$runtime->exec("UPDATE package_labels SET status='REVOKED' WHERE id=".$label['label_id']);failsIdentity(fn()=>$shipping->pdf($sender,$paidDraft['package_id']),404,'revoked label no longer downloadable');
failsIdentity(fn()=>$shipping->label($sender,$paidDraft['package_id'],Secrets::uuid()),409,'revoked label cannot be silently reissued');
$expired=$shipping->create($sender,$input,Secrets::uuid());$qid=$shipping->quote($sender,$expired['shipment_id'],['service_level'=>'STANDARD'],Secrets::uuid(),'"0"');
$p=$shipping->payment($sender,$expired['shipment_id'],['quote_id'=>$qid['quote_id']],Secrets::uuid(),'"0"');$runtime->exec("UPDATE pricing_quotes SET expires_at=now()-interval '1 second' WHERE id=".$qid['quote_id']);
check($shipping->confirmPayment($sender,$p['payment_id'],['outcome'=>'SUCCEEDED'],Secrets::uuid())['status']==='FAILED','expired quote cannot become paid even from success simulation');
check($shipping->get($sender,$expired['shipment_id'])['order_status']==='DRAFT','failed checkout retains editable draft');
echo "Local test checkout and PDF label integration passed. No real charges.\n";
// Two independent processes submit the same shipment command while the parent holds its exact lock.
$raceKey=Secrets::uuid();$raceScope='shipping:'.$shippingOrg.':'.$sender.':create:'.$raceKey;
$runtime->beginTransaction();$q=$runtime->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$q->execute([$raceScope]);
$children=[];$markers=[];
try {
 for ($i=0;$i<2;$i++) {
  $marker='shipping-race-'.bin2hex(random_bytes(6));$markers[]=$marker;
  $child=proc_open([PHP_BINARY,__DIR__.'/integration.php','--shipping-race',$marker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if (!is_resource($child)) { throw new RuntimeException('Cannot start shipment contender'); }
  fwrite($pipes[0],json_encode(['user'=>$sender,'input'=>$input,'key'=>$raceKey])."\n");fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],10);
  check(trim((string)fgets($pipes[1]))==='READY','independent shipment contender ready');$children[]=[$child,$pipes];
 }
 $waiting=false;$q=$runtime->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name IN (?,?) AND wait_event_type='Lock'");
 for ($i=0;$i<100;$i++) { $runtime->query('SELECT pg_stat_clear_snapshot()');$q->execute($markers);if ((int)$q->fetchColumn()===2) {$waiting=true;break;}usleep(10000); }
 check($waiting,'duplicate shipment requests contend on the same database lock');$runtime->commit();$results=[];
 foreach ($children as [$child,$pipes]) { $results[]=trim(stream_get_contents($pipes[1]));foreach ($pipes as $pipe) {fclose($pipe);}check(proc_close($child)===0,'shipment contender completed'); }
 $children=[];check(count(array_unique($results))===1 && ctype_digit($results[0]),'concurrent retries return the same single shipment');
} finally { if ($runtime->inTransaction()) {$runtime->rollBack();} foreach ($children as [$child,$pipes]) {foreach($pipes as $pipe){fclose($pipe);}proc_terminate($child);proc_close($child);} }
[$response,$body]=identityHttp('GET',$base.'/packages/1/label/pdf');check($response->getCode()===401,'PDF route matches and requires authentication');
$hub=(string)$runtime->query("SELECT h.id FROM hubs h JOIN locations l ON l.id=h.location_id WHERE l.organization_id=$shippingOrg")->fetchColumn();
$hubLocation=(string)$runtime->query("SELECT location_id FROM hubs WHERE id=$hub")->fetchColumn();
$runtime->exec("UPDATE packages SET current_location_id=$hubLocation WHERE id=".$shipment['package_id']);
failsIdentity(fn()=>$shipping->get($hubStaff,$sid,'operations'),404,'hub location alone cannot substitute for hub custody');
$runtime->exec("UPDATE packages SET custodian_type='HUB',custodian_ref='$hub',state='AT_HUB' WHERE id=".$shipment['package_id']);
check($shipping->get($hubStaff,$sid,'operations')['shipment_id']===$sid,'assigned hub staff sees test parcel actually in hub custody');
failsIdentity(fn()=>$shipping->cancel($sender,$sid,['reason'=>'Invalid cancellation'],Secrets::uuid(),'"1"'),409,'physical hub custody cannot be cancelled as a draft');
$package=$shipment['package_id'];
$driver=(string)$runtime->query("SELECT d.id FROM drivers d JOIN users u ON u.id=d.user_id WHERE u.organization_id=$shippingOrg LIMIT 1")->fetchColumn();
$run=(string)$runtime->query("SELECT id FROM route_runs WHERE organization_id=$shippingOrg LIMIT 1")->fetchColumn();
$operation=Secrets::uuid();$q=$runtime->prepare("INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at) VALUES (?,?,2,?,'HUB_RECEIVE','DRIVER',?,'HUB',?,?, '{}'::jsonb,now()-interval '4 minutes')");$q->execute([$package,$operation,$hubStaff,$driver,$hub,$hubLocation]);
$q=$runtime->prepare("INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at) VALUES (?,?,?,?, 'HUB_RECEIVE','REJECTED_TEST_EVIDENCE',now()-interval '3 minutes')");$q->execute([Secrets::uuid(),$package,$hubStaff,$run]);
$session=insertId($runtime,"INSERT INTO receiving_sessions(hub_id,inbound_run_id,receiver_user_id,status) VALUES ($hub,$run,$hubStaff,'CLOSED')");
$runtime->exec("INSERT INTO receiving_items(session_id,package_id,disposition) VALUES ($session,$package,'DAMAGED')");
$slot=insertId($runtime,"INSERT INTO hub_slots(hub_id,code,destination_location_id,run_id,kind) VALUES ($hub,'TRACKING-TEST',$hubLocation,$run,'STAGING')");
$runtime->exec("INSERT INTO staging_assignments(package_id,slot_id,outbound_run_id,assigned_by,routing_revision) VALUES ($package,$slot,$run,$hubStaff,1)");
$dispatch=insertId($runtime,"INSERT INTO dispatch_calls(hub_id,slot_id,outbound_run_id,destination_location_id,package_count,status,called_at,expires_at,driver_id,confirmed_at) VALUES ($hub,$slot,$run,$hubLocation,1,'ACCEPTED',now()-interval '2 minutes',now()+interval '10 minutes',$driver,now()-interval '1 minute')");
$runtime->exec("UPDATE route_runs SET dispatch_call_id=$dispatch WHERE id=$run");
$runtime->exec("INSERT INTO shipping_identifiers(package_id,si,destination_location_id) VALUES ($package,'ZPX-TRACKING-TEST',$hubLocation)");
$q=$runtime->prepare("INSERT INTO exceptions(package_id,run_id,code,status,recorded_by,organization_id,hub_id,receiving_session_id,notes) VALUES (?,?,'DAMAGED','OPEN',?,?,?,?, 'Test damage note')");$q->execute([$package,$run,$hubStaff,$shippingOrg,$hub,$session]);
$customerTracking=$shipping->tracking($sender,$sid,'customer');
check(!isset($customerTracking['package'],$customerTracking['events'],$customerTracking['current_custody']),'customer tracking omits internal custody, actor, scan, and exception evidence');
$operationsTracking=$shipping->tracking($hubStaff,$sid,'operations');
$sources=array_column($operationsTracking['events'],'source');$codes=array_column($operationsTracking['events'],'code');
check($operationsTracking['package']['package_id']===$package && $operationsTracking['current_custody']['type']==='HUB','operations tracking identifies parcel and current custody');
check(in_array('CUSTODY_EVENT',$sources,true) && in_array('SCAN_EVENT',$sources,true) && in_array('RECEIVING_ITEM',$sources,true) && in_array('STAGING_ASSIGNMENT',$sources,true) && in_array('DISPATCH_CALL',$sources,true) && in_array('EXCEPTION',$sources,true),'operations timeline combines authoritative custody, scan, receiving, staging, dispatch, and discrepancy records');
check(in_array('REJECTED_TEST_EVIDENCE',array_column($operationsTracking['events'],'result'),true) && in_array('EXCEPTION_DAMAGED',$codes,true),'rejected scans and damage discrepancies remain visible to operations');
check($shipping->searchPackages($hubStaff,$shipment['public_reference'])['items'][0]['shipment_id']===$sid,'hub-scoped package search finds public reference');
check($shipping->searchPackages($hubStaff,$operationsTracking['package']['package_uuid'])['items'][0]['shipment_id']===$sid,'package UUID search resolves the authorized shipment');
check($shipping->searchPackages($hubStaff,'ZPX-TRACKING-TEST')['items'][0]['shipment_id']===$sid,'shipping identifier search resolves the authorized shipment');
check($shipping->searchPackages($hubStaff,$package)['items'][0]['shipment_id']===$sid,'internal package ID search resolves the authorized shipment');
check($shipping->searchPackages($recipient,$shipment['public_reference'])['items']===[],'customer cannot discover packages through operations search service');
$oldOrg=getenv('ZPX_ORGANIZATION_ID');putenv('ZPX_ORGANIZATION_ID='.$identityOrg);
check($shipping->searchPackages($admin,$shipment['public_reference'])['items']===[],'operations search does not leak packages across organizations');putenv('ZPX_ORGANIZATION_ID='.$oldOrg);
echo "Shipping concurrency and hub-scoped access passed using synthetic fixtures.\n";
