<?php
declare(strict_types=1);
use Zpx\Custody\PickupRecovery;
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
$recoveryToken=Secrets::token();
$runtime->prepare("INSERT INTO auth_sessions(user_id,session_hash,client_kind,expires_at,family_id) VALUES (?,decode(?,'hex'),'BROWSER',now()+interval '1 hour',?)")
    ->execute([$senderUser,hash('sha256',$recoveryToken),uuid()]);
[$recoveryResponse,$recoveryBody]=identityHttp('GET',$base.'/admin/pickup-recovery',[],[],['zpx_delivery_session'=>$recoveryToken]);
check($recoveryResponse->getCode()===200 && count($recoveryBody['items'])>0,'admin recovery HTTP route lists active runs');
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
$reassignedOffer=$offers->refresh($driverUser)['items'];
$reassignedOrigin=array_values(array_filter($reassignedOffer,fn($o)=>$o['origin']==='Test Origin'))[0];
$reassigned=$offers->accept($driverUser,$reassignedOrigin['offer_id'],Secrets::uuid());
check($reassigned['run_id']!==$acceptedB['run_id'] && $runtime->query("SELECT assigned_run_id FROM pickup_demands WHERE package_id=$releasedPackage")->fetchColumn()===$reassigned['run_id'],'released parcel can be accepted on a new run');
check($runtime->query("SELECT COUNT(*) FROM active_allocations WHERE package_id=$releasedPackage")->fetchColumn()==1,'reassigned parcel has exactly one active allocation');
echo "Pickup recovery tests complete.\n";
