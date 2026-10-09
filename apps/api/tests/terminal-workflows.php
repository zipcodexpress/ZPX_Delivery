<?php
declare(strict_types=1);
use Zpx\Custody\PhysicalSessions;
use Zpx\Identity\Secrets;

// This fixture is reached only from integration.php inside the disposable test cluster.
$physical=new PhysicalSessions($runtime,$crypto);
$org=(string)getenv('ZPX_ORGANIZATION_ID');
$runtime->prepare("UPDATE controller_boards SET protocol_profile='SIMULATED_24' WHERE locker_id=?")->execute([$locker]);
$customer=(string)$runtime->query("SELECT u.id FROM users u JOIN scoped_role_grants g ON g.user_id=u.id JOIN roles r ON r.id=g.role_id WHERE u.organization_id=$org AND r.code='CUSTOMER' AND u.status='ACTIVE' LIMIT 1")->fetchColumn();
check($customer!=='','terminal fixture has customer');
$runtime->prepare('UPDATE user_contacts SET verified_at=now() WHERE user_id=?')->execute([$customer]);
$driver=(string)$runtime->query("SELECT d.user_id FROM route_runs r JOIN drivers d ON d.id=r.driver_id WHERE r.id=$runId")->fetchColumn();
$runtime->prepare('UPDATE user_contacts SET verified_at=now() WHERE user_id=?')->execute([$driver]);
$configAuth=new \Zpx\Custody\CabinetConfigAuth($runtime);
$configKey='fixture-key';$configSecret=Secrets::token();
$configCabinet=(string)$owner->query("SELECT cabinet_id FROM cabinet WHERE bound_locker_id=$locker LIMIT 1")->fetchColumn();
if(!$configCabinet) {
    $q=$owner->prepare("INSERT INTO cabinet(organization_id,cabinet_name,status,bound_location_id,bound_locker_id,bind_key,bind_hash,api_key,api_secret) VALUES (?,'Config auth fixture','BOUND',?,?,'fixture',?, ?,?) RETURNING cabinet_id");
    $q->execute([$org,$destLocation,$locker,str_repeat('a',64),$configKey,$configSecret]);$configCabinet=(string)$q->fetchColumn();
} else $owner->prepare('UPDATE cabinet SET api_key=?,api_secret=? WHERE cabinet_id=?')->execute([$configKey,$configSecret,$configCabinet]);
$configTime=(string)time();
$configInput=['apiKey'=>$configKey,'kts'=>$configTime,'cabinetId'=>$configCabinet,'sign'=>\Zpx\Custody\CabinetConfigAuth::signature($configKey,$configSecret,$configTime,$configCabinet)];
failsIdentity(fn()=> $configAuth->issue(array_replace($configInput,['sign'=>str_repeat('0',32)])),401,'config auth rejects incorrect signature');
$configToken=$configAuth->issue($configInput);
check($configToken['ret']===0 && $configToken['data']['expire']===86400,'config auth retains Terminal452 token envelope');
check($configAuth->authenticate($configToken['data']['accessToken'])['locker_id']===(string)$locker,'config token scopes exact bound locker');
$owner->prepare("INSERT INTO cabinet_admin_card(cabinet_id,rfid,zp_admin_id,zp_admin_role,status) VALUES (?,'fixture-admin-2468','1','SuperAdmin',1),(?,'fixture-disabled','2','Admin',0),(?,'fixture-driver','3','Driver',1)")->execute([$configCabinet,$configCabinet,$configCabinet]);
$otherQuery=$owner->prepare("INSERT INTO cabinet(organization_id,cabinet_name,status) VALUES (?,'Other admin roster fixture','DRAFT') RETURNING cabinet_id");
$otherQuery->execute([$org]);$otherCabinet=(string)$otherQuery->fetchColumn();
$owner->prepare("INSERT INTO cabinet_admin_card(cabinet_id,rfid,zp_admin_id,zp_admin_role) VALUES (?,'fixture-other','4','SuperAdmin')")->execute([$otherCabinet]);
$cards=$configAuth->adminCards($configToken['data']['accessToken']);
check(count($cards['data'])===1 && $cards['data'][0]['rfid']==='fixture-admin-2468' && $cards['data'][0]['cabinetId']===$configCabinet,'admin roster excludes disabled, unsupported-role and other-cabinet codes');
failsIdentity(fn()=> $configAuth->adminCards(str_repeat('0',64)),401,'admin roster rejects invalid cabinet token');
[$adminResponse,$adminBody]=identityHttp('POST','/cabinet/zippora/getAdminCardList',['accessToken'=>$configToken['data']['accessToken']],['content-type'=>'application/x-www-form-urlencoded']);
check($adminResponse->getCode()===200 && $adminBody===$cards && $adminResponse->getHeader('Cache-Control')==='no-store','legacy form admin roster route retains envelope and prohibits caching');
[$adminResponse,$adminBody]=identityHttp('POST','/cabinet/zippora/getAdminCardList',['accessToken'=>str_repeat('0',64)],['content-type'=>'application/x-www-form-urlencoded']);
check($adminResponse->getCode()===401 && $adminBody['data']===[],'unauthenticated roster route reveals no codes');
$owner->prepare('UPDATE cabinet_admin_card SET status=0 WHERE cabinet_id=?')->execute([$configCabinet]);
check($configAuth->adminCards($configToken['data']['accessToken'])['data']===[],'disabled code disappears immediately');
$owner->prepare("UPDATE cabinet_admin_card SET status=1 WHERE cabinet_id=? AND rfid='fixture-admin-2468'")->execute([$configCabinet]);
$configHeaders=['x-cabinet-access-token'=>$configToken['data']['accessToken'],'x-device-nonce'=>uuid()];
[$configResponse,$configBody]=identityHttp('GET','/api/delivery/v1/devices/me/commands',[],$configHeaders);
check($configResponse->getCode()===200,'config token authenticates actual device command route');
[$configResponse,$configBody]=identityHttp('GET','/api/delivery/v1/devices/me/commands',[],$configHeaders);
check($configResponse->getCode()===409,'config token rejects repeated request nonce');
$owner->prepare('UPDATE cabinet SET api_secret=? WHERE cabinet_id=?')->execute([Secrets::token(),$configCabinet]);
failsIdentity(fn()=> $configAuth->authenticate($configToken['data']['accessToken']),401,'changing config secret invalidates issued token');
$owner->prepare('UPDATE cabinet SET api_secret=? WHERE cabinet_id=?')->execute([$configSecret,$configCabinet]);
function terminalScene(PDO $db,string $device,string $actor,string $action): string {
    return insertId($db,"INSERT INTO terminal_pairing_sessions(scene_uuid,device_id,actor_user_id,workflow,status,expires_at) VALUES (?,?,?,?,'APPROVED',now()+interval '10 minutes')",[uuid(),$device,$actor,$action]);
}
function terminalObserve(string $session,string $command,string $keyId,string $secret): void {
    $commands='/api/delivery/v1/devices/me/commands';
    [$r,$b]=identityHttp('GET',$commands,[],signedDeviceHeaders($keyId,$secret,null,null,$commands,'GET',[]));
    check($r->getCode()===200 && in_array($command,array_column($b['items'] ?? [],'command_id'),true),'authorized workflow reaches signed command poll');
    foreach (['DISPATCH_RECORDED','OPEN_OBSERVED','CLOSE_OBSERVED'] as $event) {
        $path='/api/delivery/v1/devices/me/command-events';$input=['command_id'=>$command,'event_id'=>uuid(),'event_type'=>$event];
        [$r,$b]=identityHttp('POST',$path,$input,signedDeviceHeaders($keyId,$secret,null,null,$path,'POST',$input));
        check($r->getCode()===200 && ($b['custody_transferred'] ?? null)===false,'signed '.$event.' retains custody until actor confirmation');
    }
}

// Recipient collection of the earlier confirmed final deposit.
$recipientPackage=(string)$packageIds[0];
$recipientShipment=(string)$runtime->query("SELECT shipment_id FROM packages WHERE id=$recipientPackage")->fetchColumn();
$runtime->prepare("INSERT INTO shipment_parties(shipment_id,party_role,user_id,contact_encrypted) VALUES (?,'RECIPIENT',?,?) ON CONFLICT (shipment_id,party_role) DO UPDATE SET user_id=EXCLUDED.user_id")->execute([$recipientShipment,$customer,$crypto->encrypt('{}')]);
$version=(int)$runtime->query("SELECT version FROM packages WHERE id=$recipientPackage")->fetchColumn();
$grant=$physical->issueGrant($customer,['package_id'=>$recipientPackage,'expected_package_version'=>$version],uuid());
$pair=terminalScene($runtime,(string)$device,$customer,'RECIPIENT_PICKUP');
$input=['workflow'=>'RECIPIENT_PICKUP','package_id'=>$recipientPackage,'pairing_id'=>$pair,'expected_package_version'=>$version,'pickup_token'=>$grant['pickup_token']];
failsIdentity(fn()=> $physical->prepare($customer,array_replace($input,['pickup_token'=>'wrong']),uuid()),422,'wrong recipient grant cannot prepare door');
$prepared=$physical->prepare($customer,$input,$key=uuid());
check($physical->prepare($customer,$input,$key)==$prepared,'recipient preparation idempotent');
failsIdentity(fn()=> $physical->prepare($customer,array_replace($input,['expected_package_version'=>$version+1]),$key),409,'same preparation key cannot change parcel version');
failsIdentity(fn()=> $physical->status($prepared['session_id'],$driver),404,'another actor cannot read recipient session');
failsIdentity(fn()=> $physical->confirm($driver,$prepared['session_id'],['attested'=>true,'expected_package_version'=>$version],uuid()),404,'another actor cannot confirm recipient collection');
failsIdentity(fn()=> $physical->confirm($customer,$prepared['session_id'],['attested'=>true,'expected_package_version'=>$version],uuid()),409,'collection cannot confirm without door evidence');
terminalObserve($prepared['session_id'],$prepared['command_id'],$deviceKeyId,$deviceSecret);
$path='/api/delivery/v1/devices/me/sessions/'.$prepared['session_id'];
[$r,$b]=identityHttp('GET',$path,[],signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,$path,'GET',[]));
check($r->getCode()===200 && $b['status']==='CLOSED' && !$b['custody_transferred'],'terminal session projection distinguishes close from custody');
$confirmPath='/api/delivery/v1/devices/me/sessions/'.$prepared['session_id'].'/confirm';$confirmInput=['attested'=>true,'expected_package_version'=>$version];$confirmKey=uuid();
[$confirmResponse,$confirmed]=identityHttp('POST',$confirmPath,$confirmInput,signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,$confirmPath,'POST',$confirmInput)+['idempotency-key'=>$confirmKey]);
check($confirmResponse->getCode()===200,'paired terminal confirmation authenticates actual device route');
check($confirmed['package_state']==='COLLECTED' && $physical->confirm($customer,$prepared['session_id'],['attested'=>true,'expected_package_version'=>$version],$confirmKey)==$confirmed,'recipient confirmation is one-time and idempotent');
check(!$runtime->query("SELECT 1 FROM compartment_claims WHERE package_id=$recipientPackage")->fetchColumn(),'collected parcel frees exact occupied door');

// Carrier final-deposit size change follows the same closed/declined/reselect flow.
$carrierParcel=(string)$packageIds[1];
$carrierVersion=(int)$runtime->query("SELECT version FROM packages WHERE id=$carrierParcel")->fetchColumn();
$carrierLabel=Secrets::token();$previousHash=(string)$owner->query("SELECT encode(token_hash,'hex') FROM package_labels WHERE package_id=$carrierParcel AND status='ACTIVE'")->fetchColumn();
$owner->prepare("UPDATE package_labels SET token_hash=decode(?,'hex') WHERE package_id=? AND status='ACTIVE'")->execute([hash('sha256',$carrierLabel),$carrierParcel]);
$carrierPair=terminalScene($runtime,(string)$device,$driver,'FINAL_DEPOSIT');
$carrierAttempt=$physical->scanAtTerminal((string)$device,$carrierPair,['label_payload'=>$carrierLabel],uuid());
check($carrierAttempt['workflow']==='FINAL_DEPOSIT','carrier scanner resolves exact arrived assigned final stop');
terminalObserve($carrierAttempt['session_id'],$carrierAttempt['command_id'],$deviceKeyId,$deviceSecret);
$physical->deviceDecision((string)$device,$carrierAttempt['session_id'],['expected_package_version'=>$carrierVersion],uuid(),true);
$carrierModel=(string)$owner->query('SELECT bx.box_model_id FROM cabinet_box bx JOIN locker_sessions ls ON ls.compartment_id=bx.compartment_id WHERE ls.id='.$carrierAttempt['session_id'])->fetchColumn();
$carrierRetryKey=uuid();$carrierRetry=$physical->retryDeposit((string)$device,$carrierAttempt['session_id'],['box_model_id'=>$carrierModel],$carrierRetryKey);
check($carrierRetry['workflow']==='FINAL_DEPOSIT' && $carrierRetry['session_id']!==$carrierAttempt['session_id'],'carrier selects model without rescanning and receives fresh final-deposit attempt');
check($physical->retryDeposit((string)$device,$carrierAttempt['session_id'],['box_model_id'=>$carrierModel],$carrierRetryKey)==$carrierRetry,'carrier model retry is idempotent');
terminalObserve($carrierRetry['session_id'],$carrierRetry['command_id'],$deviceKeyId,$deviceSecret);
$physical->deviceDecision((string)$device,$carrierRetry['session_id'],['expected_package_version'=>$carrierVersion],uuid(),true);
check($runtime->query("SELECT state FROM packages WHERE id=$carrierParcel")->fetchColumn()==='OUTBOUND_CUSTODY' && (int)$runtime->query("SELECT version FROM packages WHERE id=$carrierParcel")->fetchColumn()===$carrierVersion,'carrier didnt-deposit keeps parcel in driver custody and retains version');
$owner->prepare("UPDATE package_labels SET token_hash=decode(?,'hex') WHERE package_id=? AND status='ACTIVE'")->execute([$previousHash,$carrierParcel]);

// A new paid origin parcel uses the free physical door, then assigned inbound removal.
$workflowDestination=insertId($runtime,"INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES (?,?, 'Workflow destination','LOCKER','Synthetic','DELIVERY_ONLY','ACTIVE','{}')",[$org,'WORKFLOW-'.bin2hex(random_bytes(8))]);
$shipment=insertId($runtime,"INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES (?,?,?, ?,?,'STANDARD','READY','PAID')",[$org,$customer,'TERMINAL-'.bin2hex(random_bytes(8)),$destLocation,$workflowDestination]);
$parcel=insertId($runtime,"INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref) VALUES (?,?,1,100,100,100,100,'CREATED','SENDER',?)",[$shipment,uuid(),$customer]);
$runtime->prepare("INSERT INTO shipping_identifiers(package_id,si,destination_location_id) VALUES (?,?,?)")->execute([$parcel,'SI-TERMINAL-'.uuid(),$workflowDestination]);
$label=Secrets::token();
$runtime->prepare("INSERT INTO package_labels(package_id,label_version,token_hash,status,expires_at) VALUES (?,1,decode(?,'hex'),'ACTIVE',now()+interval '1 day')")->execute([$parcel,hash('sha256',$label)]);
$pair=terminalScene($runtime,(string)$device,$customer,'ORIGIN_DEPOSIT');
$input=['workflow'=>'ORIGIN_DEPOSIT','package_id'=>$parcel,'pairing_id'=>$pair,'expected_package_version'=>0,'label_payload'=>$label];
$scanPath='/api/delivery/v1/devices/me/pairings/'.$pair.'/scan';$scanInput=['label_payload'=>$label];$scanKey=uuid();
failsIdentity(fn()=> $physical->scanAtTerminal('999999999',$pair,$scanInput,uuid()),409,'scanner cannot use another terminal pairing');
[$scanResponse,$origin]=identityHttp('POST',$scanPath,$scanInput,signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,$scanPath,'POST',$scanInput)+['idempotency-key'=>$scanKey]);
check($scanResponse->getCode()===200,'approved sender can prepare through native scanner API');
[$scanResponse,$scanReplay]=identityHttp('POST',$scanPath,$scanInput,signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,$scanPath,'POST',$scanInput)+['idempotency-key'=>$scanKey]);
check($scanResponse->getCode()===200 && $scanReplay==$origin,'scanner retry with fresh nonce retains exact prepared session');
failsIdentity(fn()=> $physical->didntDeposit($customer,$origin['session_id'],['expected_package_version'=>0],uuid()),409,'didnt deposit requires observed closure');
terminalObserve($origin['session_id'],$origin['command_id'],$deviceKeyId,$deviceSecret);
failsIdentity(fn()=> $physical->didntDeposit($driver,$origin['session_id'],['expected_package_version'=>0],uuid()),404,'another actor cannot release reservation');
$declineKey=uuid();$declineInput=['expected_package_version'=>0];$declinePath='/api/delivery/v1/devices/me/sessions/'.$origin['session_id'].'/didnt-deposit';
[$declineResponse,$declined]=identityHttp('POST',$declinePath,$declineInput,signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,$declinePath,'POST',$declineInput)+['idempotency-key'=>$declineKey]);
check($declineResponse->getCode()===200,'paired terminal didnt-deposit route releases closed attempt');
check($declined['attempt_abandoned'] && !$declined['custody_transferred'],'didnt deposit abandons attempt without custody');
check($physical->didntDeposit($customer,$origin['session_id'],['expected_package_version'=>0],$declineKey)===$declined,'didnt deposit retries idempotently');
check(!$runtime->query("SELECT 1 FROM compartment_claims WHERE session_id=".$origin['session_id'])->fetchColumn(),'didnt deposit releases held door');
check((int)$runtime->query("SELECT version FROM packages WHERE id=$parcel")->fetchColumn()===0,'didnt deposit retains parcel version');
failsIdentity(fn()=> $physical->retryDeposit((string)$device,$origin['session_id'],['box_model_id'=>'999999999'],uuid()),409,'retry rejects unavailable selected model without reserving another size');
$retryModel=(string)$owner->query('SELECT bx.box_model_id FROM cabinet_box bx JOIN locker_sessions ls ON ls.compartment_id=bx.compartment_id WHERE ls.id='.$origin['session_id'])->fetchColumn();
check($retryModel!=='','retry fixture retains existing cabinet model mapping');
$retryInput=['box_model_id'=>$retryModel];$retryKey=uuid();$declinedSession=$origin['session_id'];
$origin=$physical->retryDeposit((string)$device,$declinedSession,$retryInput,$retryKey);
check($physical->retryDeposit((string)$device,$declinedSession,$retryInput,$retryKey)==$origin,'selected-size retry is idempotent without scanning label again');
check($origin['session_id']!==$declinedSession,'size retry creates a fresh door attempt');
check($runtime->query("SELECT state FROM packages WHERE id=$parcel")->fetchColumn()==='CREATED','origin preparation does not pretend package deposited');
check(json_encode($physical->status($origin['session_id'],$customer)['workflow_context'])==='{}','empty workflow context serializes as the contract object');
$commandsPath='/api/delivery/v1/devices/me/commands';
$runtime->prepare("UPDATE packages SET version=1 WHERE id=?")->execute([$parcel]);
[$r,$b]=identityHttp('GET',$commandsPath,[],signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,$commandsPath,'GET',[]));
check(!in_array($origin['command_id'],array_column($b['items'] ?? [],'command_id'),true),'stale origin parcel version suppresses door command');
$runtime->prepare("UPDATE packages SET version=0 WHERE id=?")->execute([$parcel]);
$runtime->prepare("UPDATE controller_boards SET protocol_profile='V2_24' WHERE locker_id=?")->execute([$locker]);
[$r,$b]=identityHttp('GET',$commandsPath,[],signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,$commandsPath,'GET',[]));
check(!in_array($origin['command_id'],array_column($b['items'] ?? [],'command_id'),true),'uncommissioned physical profile cannot dispatch new workflow');
$runtime->prepare("UPDATE controller_boards SET protocol_profile='SIMULATED_24' WHERE locker_id=?")->execute([$locker]);
if(getenv('ZPX_TERMINAL_HTTP_TEST')) terminalNativeHttp($deviceKeyId,$deviceSecret,$prepared['session_id'],$origin['session_id']);
else terminalObserve($origin['session_id'],$origin['command_id'],$deviceKeyId,$deviceSecret);
$originResult=$physical->confirm($customer,$origin['session_id'],['attested'=>true,'expected_package_version'=>0],uuid());
check($originResult['package_state']==='AT_ORIGIN','signed origin evidence and sender confirmation commit origin custody');
$runContext=$runtime->query("SELECT hub_id,vehicle_id,driver_id FROM route_runs WHERE id=$runId")->fetch(PDO::FETCH_ASSOC);
$inbound=insertId($runtime,"INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,revision,planned_start,planned_end) VALUES (?,?,?,?,'INBOUND','IN_PROGRESS',1,now(),now()+interval '2 hours')",[$org,$runContext['hub_id'],$runContext['driver_id'],$runContext['vehicle_id']]);
$stop=insertId($runtime,"INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES (?,?,1,'EXPECTED')",[$inbound,$destLocation]);
$inboundManifest=insertId($runtime,"INSERT INTO manifests(run_id,revision,state) VALUES (?,1,'ACTIVE')",[$inbound]);
$runtime->prepare("INSERT INTO manifest_items(manifest_id,run_id,stop_id,package_id,state) VALUES (?,?,?,?,'EXPECTED')")->execute([$inboundManifest,$inbound,$stop,$parcel]);
$runtime->prepare("UPDATE pickup_demands SET status='ASSIGNED',assigned_run_id=? WHERE package_id=?")->execute([$inbound,$parcel]);
$arrivalService=new Zpx\Custody\Service($runtime,$crypto);
$arrivalKey=uuid();
failsIdentity(fn()=>$arrivalService->arriveAtStop($customer,$inbound,$stop,['expected_revision'=>1],uuid()),403,'customer cannot report carrier arrival');
$runtime->prepare("UPDATE pickup_demands SET status='OPEN' WHERE package_id=?")->execute([$parcel]);
failsIdentity(fn()=>$arrivalService->arriveAtStop($driver,$inbound,$stop,['expected_revision'=>1],uuid()),409,'inbound arrival rejects lost pickup assignment');
$runtime->prepare("UPDATE pickup_demands SET status='ASSIGNED' WHERE package_id=?")->execute([$parcel]);
$arrival=$arrivalService->arriveAtStop($driver,$inbound,$stop,['expected_revision'=>1],$arrivalKey);
check($arrival['state']==='ARRIVED' && $arrival['run_revision']===2 && $arrival['packages_in_driver_custody']===0,'inbound arrival records stop without transferring custody');
check($arrivalService->arriveAtStop($driver,$inbound,$stop,['expected_revision'=>1],$arrivalKey)==$arrival,'inbound arrival retry is idempotent');
failsIdentity(fn()=>$arrivalService->arriveAtStop($driver,$inbound,$stop,['expected_revision'=>1],uuid()),409,'inbound arrival rejects stale run revision');
$pair=terminalScene($runtime,(string)$device,$driver,'INBOUND_PICKUP');
$input=['workflow'=>'INBOUND_PICKUP','package_id'=>$parcel,'pairing_id'=>$pair,'expected_package_version'=>1,'label_payload'=>$label,'run_id'=>$inbound,'stop_id'=>$stop,'expected_revision'=>2];
$pickup=$physical->scanAtTerminal((string)$device,$pair,['label_payload'=>$label],uuid());
check($runtime->query("SELECT state FROM packages WHERE id=$parcel")->fetchColumn()==='AT_ORIGIN','inbound preparation retains origin custody');
terminalObserve($pickup['session_id'],$pickup['command_id'],$deviceKeyId,$deviceSecret);
$result=$physical->confirm($driver,$pickup['session_id'],['attested'=>true,'expected_package_version'=>1],uuid());
check($result['package_state']==='INBOUND_CUSTODY' && $runtime->query("SELECT state FROM manifest_items WHERE run_id=$inbound")->fetchColumn()==='LOADED','inbound evidence/driver confirmation commits exact assigned parcel');
check($runtime->query("SELECT state FROM route_run_stops WHERE id=$stop")->fetchColumn()==='COMPLETED','collecting every inbound parcel completes the stop');
echo "Enrolled terminal origin, inbound and recipient workflow tests passed. No physical hardware tested.\n";
function terminalNativeHttp(string $keyId,string $secret,string $confirmedSession,string $pendingSession): void {
    global $configKey,$configSecret,$configCabinet;
    $path=getenv('ZPX_TERMINAL_HTTP_FIXTURE');
    file_put_contents($path,json_encode(['endpoint'=>getenv('ZPX_TERMINAL_HTTP_ENDPOINT'),'key_id'=>$keyId,'seed_base64'=>base64_encode(substr($secret,0,32)),'session_id'=>$confirmedSession,'operation_session'=>$pendingSession,'config_key'=>$configKey,'config_secret'=>$configSecret,'config_cabinet'=>$configCabinet],JSON_THROW_ON_ERROR));
    $httpPort=parse_url(getenv('ZPX_TERMINAL_HTTP_ENDPOINT'),PHP_URL_PORT);
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/index.php'],[1=>['file',$path.'.server.log','a'],2=>['file',$path.'.server.log','a']],$serverPipes);
    if(!is_resource($server)){throw new RuntimeException('Disposable HTTP server could not start');}
    try {
    for($attempt=0;$attempt<20;$attempt++){ $socket=@fsockopen('127.0.0.1',$httpPort,$errno,$errstr,.1);if($socket){fclose($socket);break;}usleep(100000); }
    $process=proc_open([getenv('ZPX_TERMINAL_HTTP_TEST'),'--device-http',$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Native HTTP test could not start');}
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);unlink($path);
    echo $out;check($exit===0,'native Windows client passed disposable API HTTP test: '.$error);
    } finally {proc_terminate($server);proc_close($server);if(is_file($path))unlink($path);}
}
