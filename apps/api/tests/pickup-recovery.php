<?php
declare(strict_types=1);
use Zpx\Custody\PickupRecovery;
use Zpx\HubReceiving\Service as HubReceiving;
use Zpx\Identity\Secrets;

$recovery=new PickupRecovery($runtime,$crypto);
failsIdentity(fn()=>$recovery->list($otherDriverUser),403,'driver cannot list pickup recovery');
failsIdentity(fn()=>$recovery->release($otherDriverUser,$acceptedB['run_id'],['reason'=>'Missed pickup','expected_revision'=>$acceptedB['revision']],Secrets::uuid()),403,'driver cannot release a run');
failsIdentity(fn()=>$recovery->release($senderUser,$accepted['run_id'],['reason'=>'Missed pickup','expected_revision'=>$second['revision']],Secrets::uuid()),409,'partially collected run cannot be released');
$recoveryList=$recovery->list($senderUser)['items'];
$pendingRecovery=array_values(array_filter($recoveryList,fn($r)=>$r['run_id']===$acceptedB['run_id']))[0];
check($pendingRecovery['can_release'] && $pendingRecovery['package_count']===1,'admin sees wholly uncollected run as releasable');
$partialRecovery=array_values(array_filter($recoveryList,fn($r)=>$r['run_id']===$accepted['run_id']))[0];
check(!$partialRecovery['can_release'],'admin sees partially collected run as requiring reconciliation');
check($partialRecovery['collected_count']===1 && count(array_filter($partialRecovery['parcels'],fn($p)=>$p['can_release']))===1,'admin sees the exact uncollected parcel on a partial run');
$recoveryToken=Secrets::token();
$runtime->prepare("INSERT INTO auth_sessions(user_id,session_hash,client_kind,expires_at,family_id) VALUES (?,decode(?,'hex'),'BROWSER',now()+interval '1 hour',?)")
    ->execute([$senderUser,hash('sha256',$recoveryToken),uuid()]);
[$recoveryResponse,$recoveryBody]=identityHttp('GET',$base.'/admin/pickup-recovery',[],[],['zpx_delivery_session'=>$recoveryToken]);
check($recoveryResponse->getCode()===200 && count($recoveryBody['items'])>0,'admin recovery HTTP route lists active runs');
$partialPackage=(string)$offerPackages[1][0];
$collectedPackage=(string)$offerPackages[0][0];
$partialInput=['reason'=>'Driver could not collect remaining parcel','evidence_kind'=>'SITE_INSPECTION',
    'evidence_reference'=>'site-visit-test-001','expected_revision'=>$second['revision']];
failsIdentity(fn()=>$recovery->releaseItem($otherDriverUser,$accepted['run_id'],$partialPackage,$partialInput,Secrets::uuid()),403,'driver cannot release a parcel');
failsIdentity(fn()=>$recovery->releaseItem($senderUser,$accepted['run_id'],$partialPackage,[...$partialInput,'evidence_reference'=>''],Secrets::uuid()),422,'partial release requires evidence reference');
failsIdentity(fn()=>$recovery->releaseItem($senderUser,$accepted['run_id'],$partialPackage,[...$partialInput,'expected_revision'=>$second['revision']+1],Secrets::uuid()),409,'partial release rejects stale run revision');
failsIdentity(fn()=>$recovery->releaseItem($senderUser,$accepted['run_id'],$collectedPackage,$partialInput,Secrets::uuid()),409,'collected parcel cannot be released');
$collectedBefore=$runtime->query("SELECT state,custodian_type,custodian_ref,version FROM packages WHERE id=$collectedPackage")->fetch(PDO::FETCH_ASSOC);
$partialBefore=$runtime->query("SELECT state,custodian_type,custodian_ref,current_location_id,version FROM packages WHERE id=$partialPackage")->fetch(PDO::FETCH_ASSOC);
$partialKey=Secrets::uuid();
[$partialResponse,$partialResult]=identityHttp('POST',$base.'/admin/pickup-recovery/'.$accepted['run_id'].'/parcels/'.$partialPackage.'/release',$partialInput,
    ['origin'=>'http://localhost:5173','x-csrf-token'=>$crypto->digest('csrf',$recoveryToken),'idempotency-key'=>$partialKey],['zpx_delivery_session'=>$recoveryToken]);
check($partialResponse->getCode()===200 && $partialResult['state']==='RELEASED','admin HTTP route releases only the verified uncollected parcel');
check($recovery->releaseItem($senderUser,$accepted['run_id'],$partialPackage,$partialInput,$partialKey)['revision']===$partialResult['revision'],'partial release is idempotent');
failsIdentity(fn()=>$recovery->releaseItem($senderUser,$accepted['run_id'],$partialPackage,[...$partialInput,'evidence_reference'=>'different-reference'],$partialKey),409,'partial release key cannot change evidence');
check($runtime->query("SELECT state FROM manifest_items WHERE run_id={$accepted['run_id']} AND package_id=$partialPackage")->fetchColumn()==='RELEASED','old manifest retains released item for audit');
check($runtime->query("SELECT COUNT(*) FROM active_allocations WHERE package_id=$partialPackage")->fetchColumn()==0,'partial release removes only the uncollected allocation');
check($runtime->query("SELECT status FROM pickup_demands WHERE package_id=$partialPackage")->fetchColumn()==='OPEN','partial release reopens only the uncollected demand');
check($runtime->query("SELECT state,custodian_type,custodian_ref,version FROM packages WHERE id=$collectedPackage")->fetch(PDO::FETCH_ASSOC)===$collectedBefore,'collected parcel retains driver custody during partial release');
check($runtime->query("SELECT state,custodian_type,custodian_ref,current_location_id,version FROM packages WHERE id=$partialPackage")->fetch(PDO::FETCH_ASSOC)===$partialBefore,'released parcel retains locker custody and version');
$partialRunView=array_values(array_filter($custody->listRuns($driverUser)['items'],fn($r)=>$r['id']===$accepted['run_id']))[0];
check($partialRunView['expected_count']===1 && $partialRunView['loaded_count']===1,'driver sees only the collected parcel in active run counts');
failsIdentity(fn()=>$custody->resolveScan($driverUser,$offerPackages[1][1],'INBOUND_PICKUP',$accepted['run_id']),409,'old run no longer authorizes released parcel pickup');
failsIdentity(fn()=>$custody->inboundPickupScan($driverUser,$accepted['run_id'],[
    'label_payload'=>$offerPackages[1][1],'action'=>'INBOUND_PICKUP','client_event_id'=>Secrets::uuid(),
    'run_revision'=>$partialResult['revision'],'expected_package_version'=>1,
],Secrets::uuid(),'"'.$partialResult['revision'].'"'),409,'direct stale pickup attempt cannot transfer a released parcel');
$runtime->exec("INSERT INTO hub_staff(hub_id,user_id) VALUES ($hub,$senderUser)");
$hubStaffRole=$runtime->query("SELECT id FROM roles WHERE code='HUB_STAFF'")->fetchColumn();
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,location_id,granted_by) VALUES ($senderUser,$hubStaffRole,$custodyOrg,$hubLocation,$senderUser)");
$receiving=new HubReceiving($runtime,$crypto);
$partialSession=$receiving->openSession($senderUser,['hub_id'=>$hub,'inbound_run_id'=>$accepted['run_id']],Secrets::uuid());
check($partialSession['expected_count']===1,'hub receiving expects only the collected parcel');
$partialReceive=$receiving->receiveScan($senderUser,['label_payload'=>$offerPackages[0][1],
    'inbound_run_id'=>$accepted['run_id'],'receiving_session_id'=>$partialSession['receiving_session_id'],
    'expected_package_version'=>2,'disposition'=>'RECEIVED'],Secrets::uuid());
check($partialReceive['received_count']===1 && $partialReceive['expected_count']===1,'hub receives collected parcel without counting released parcel');
$partialClosed=$receiving->closeSession($senderUser,$partialSession['receiving_session_id'],Secrets::uuid());
check($partialClosed['short_count']===0 && $partialClosed['expected_count']===1,'closing partial run does not create false SHORT discrepancy');
check($runtime->query("SELECT state FROM manifest_items WHERE run_id={$accepted['run_id']} AND package_id=$partialPackage")->fetchColumn()==='RELEASED','hub close preserves released manifest evidence');
failsIdentity(fn()=>$recovery->release($senderUser,$acceptedB['run_id'],['reason'=>'Missed pickup','expected_revision'=>$acceptedB['revision']+1],Secrets::uuid()),409,'stale run revision cannot release parcels');
$releasedPackage=(string)$runtime->query("SELECT package_id FROM manifest_items WHERE run_id={$acceptedB['run_id']}")->fetchColumn();
$beforeCustody=$runtime->query("SELECT state,custodian_type,custodian_ref,current_location_id,version FROM packages WHERE id=$releasedPackage")->fetch(PDO::FETCH_ASSOC);
$releaseKey=Secrets::uuid();
$releaseInput=['reason'=>'Driver missed scheduled collection','expected_revision'=>$acceptedB['revision']];
[$recoveryResponse,$release]=identityHttp('POST',$base.'/admin/pickup-recovery/'.$acceptedB['run_id'].'/release',$releaseInput,
    ['origin'=>'http://localhost:5173','x-csrf-token'=>$crypto->digest('csrf',$recoveryToken),'idempotency-key'=>$releaseKey],['zpx_delivery_session'=>$recoveryToken]);
check($recoveryResponse->getCode()===200,'admin recovery HTTP route accepts authorized release');
check($release['state']==='CANCELLED' && $release['released_count']===1,'admin cancels wholly uncollected run');
$releaseReplay=$recovery->release($senderUser,$acceptedB['run_id'],['reason'=>'Driver missed scheduled collection','expected_revision'=>$acceptedB['revision']],$releaseKey);
check($releaseReplay['run_id']===$release['run_id'] && $releaseReplay['revision']===$release['revision'] && $releaseReplay['released_count']===$release['released_count'],'recovery retries return same outcome');
failsIdentity(fn()=>$recovery->release($senderUser,$acceptedB['run_id'],['reason'=>'Different reason','expected_revision'=>$acceptedB['revision']],$releaseKey),409,'recovery key cannot be reused for different reason');
$afterCustody=$runtime->query("SELECT state,custodian_type,custodian_ref,current_location_id,version FROM packages WHERE id=$releasedPackage")->fetch(PDO::FETCH_ASSOC);
check($beforeCustody===$afterCustody,'recovery leaves package state, custodian, locker and version unchanged');
check($runtime->query("SELECT COUNT(*) FROM active_allocations WHERE package_id=$releasedPackage")->fetchColumn()==0,'released parcel has no active allocation');
check($runtime->query("SELECT status FROM pickup_demands WHERE package_id=$releasedPackage")->fetchColumn()==='OPEN','released parcel demand reopens');
check($runtime->query("SELECT COUNT(*) FROM package_events WHERE package_id=$releasedPackage AND event_type='PICKUP_ASSIGNMENT_RELEASED'")->fetchColumn()==1,'release has one auditable package event');
failsIdentity(fn()=>$custody->acknowledgeRun($otherDriverUser,$acceptedB['run_id'],Secrets::uuid()),409,'cancelled run cannot be acknowledged');
$routing->assign($senderUser,['origin_location_id'=>$originB,'hub_id'=>$hub,'expected_version'=>0],Secrets::uuid());
$reassignedOffer=$offers->refresh($driverUser)['items'];
$reassignedOrigin=array_values(array_filter($reassignedOffer,fn($o)=>$o['origin']==='Test Origin'))[0];
$reassigned=$offers->accept($driverUser,$reassignedOrigin['offer_id'],Secrets::uuid());
check($reassigned['run_id']!==$acceptedB['run_id'] && $runtime->query("SELECT assigned_run_id FROM pickup_demands WHERE package_id=$releasedPackage")->fetchColumn()===$reassigned['run_id'],'released parcel can be accepted on a new run');
$partialOffer=array_values(array_filter($reassignedOffer,fn($o)=>$o['origin']==='Offer origin B'))[0];
$partialReassigned=$offers->accept($driverUser,$partialOffer['offer_id'],Secrets::uuid());
check($partialReassigned['run_id']===$reassigned['run_id'],'nearby partial recovery work appends to new published run');
check($runtime->query("SELECT assigned_run_id FROM pickup_demands WHERE package_id=$partialPackage")->fetchColumn()===$reassigned['run_id'],'parcel from partial run can be reassigned independently');
check($runtime->query("SELECT COUNT(*) FROM active_allocations WHERE package_id=$releasedPackage")->fetchColumn()==1,'reassigned parcel has exactly one active allocation');
echo "Pickup recovery tests complete.\n";
