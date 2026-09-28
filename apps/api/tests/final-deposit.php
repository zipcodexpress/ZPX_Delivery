<?php
declare(strict_types=1);
use Zpx\Custody\FinalDeposit;
use Zpx\Identity\Secrets;

$final=new FinalDeposit($runtime,$crypto);
foreach (array_slice($packageIds,0,2) as $index=>$packageId) {
    $runtime->prepare('INSERT INTO shipping_identifiers(package_id,si,destination_location_id) VALUES (?,?,?)')
        ->execute([$packageId,'ZPX-FINAL-'.uuid(),$destLocation]);
}
$locker=insertId($runtime,"INSERT INTO lockers(location_id,capabilities) VALUES (?,?::jsonb)",[$destLocation,json_encode(['synthetic'=>false,'physical_commands_enabled'=>true])]);
$device=insertId($runtime,"INSERT INTO locker_devices(locker_id,external_device_id,status) VALUES (?,?,'ACTIVE')",[$locker,'test-final-'.uuid()]);
$runtime->prepare("INSERT INTO device_credentials(device_id,key_id,public_key,valid_from) VALUES (?,?,?,now()-interval '1 hour')")
    ->execute([$device,'test-key-'.uuid(),'test-public-key']);
$door=insertId($runtime,"INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status) VALUES (?,'D1',200,200,200,1000,'AVAILABLE')",[$locker]);
$ownership=insertId($owner,"INSERT INTO ownership_manifests(locker_id,generation,manifest_hash,signature_reference,state,issued_by,activated_at)
    VALUES (?,1,decode(repeat('aa',32),'hex'),'test-commissioned','ACTIVE',?,now())",[$locker,$staffUser]);
$owner->prepare("INSERT INTO compartment_ownership(compartment_id,locker_id,manifest_id,owner,generation) VALUES (?,?,?,'DELIVERY',1)")
    ->execute([$door,$locker,$ownership]);
$pairing=insertId($runtime,"INSERT INTO terminal_pairing_sessions(scene_uuid,device_id,actor_user_id,workflow,status,expires_at)
    VALUES (?,?,?,'FINAL_DEPOSIT','APPROVED',now()+interval '10 minutes')",[uuid(),$device,$driverUser]);
$version=(int)$runtime->query("SELECT version FROM packages WHERE id={$packageIds[0]}")->fetchColumn();
$input=['package_id'=>$packageIds[0],'pairing_id'=>$pairing,'label_payload'=>$labelTokens[0],
    'expected_package_version'=>$version,'expected_revision'=>2];
failsIdentity(fn()=> $final->prepare($staffUser,$runId,$firstStop,$input,Secrets::uuid(),'"2"'),403,'final deposit requires driver role');
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$secondStop,$input,Secrets::uuid(),'"2"'),404,'final deposit requires exact arrived stop');
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,$input+['ignored'=>1],Secrets::uuid(),'"2"'),422,'final deposit rejects extra request fields');
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,array_replace($input,['expected_revision'=>1]),Secrets::uuid(),'"1"'),409,'final deposit rejects stale run revision');
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,array_replace($input,['expected_package_version'=>$version-1]),Secrets::uuid(),'"2"'),409,'final deposit rejects stale parcel version');
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,array_replace($input,['label_payload'=>'wrong']),Secrets::uuid(),'"2"'),422,'final deposit rejects invalid label');
$runtime->exec("UPDATE locker_devices SET status='OFFLINE' WHERE id=$device");
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,$input,Secrets::uuid(),'"2"'),409,'offline destination device cannot prepare command');
$runtime->exec("UPDATE locker_devices SET status='ACTIVE' WHERE id=$device");
$runtime->exec("UPDATE device_credentials SET revoked_at=now() WHERE device_id=$device");
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,$input,Secrets::uuid(),'"2"'),409,'revoked device credential blocks deposit');
$runtime->exec("UPDATE device_credentials SET revoked_at=NULL WHERE device_id=$device");
$owner->exec("UPDATE compartment_ownership SET owner='FROZEN' WHERE compartment_id=$door");
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,$input,Secrets::uuid(),'"2"'),409,'frozen compartment ownership blocks deposit');
$owner->exec("UPDATE compartment_ownership SET owner='DELIVERY' WHERE compartment_id=$door");
$key=Secrets::uuid();
[$response,$body]=identityHttp('POST',$base.'/runs/'.$runId.'/stops/'.$firstStop.'/final-deposits',$input,
    ['idempotency-key'=>$key,'x-csrf-token'=>$driverLogin[1]['csrf_token'],'if-match'=>'"2"'],$driverCookies);
check($response->getCode()===200 && $body['status']==='READY' && $body['custody_transferred']===false && $body['awaiting_device_evidence']===true,'final deposit reserves physical compartment without claiming delivery');
$replay=$final->prepare($driverUser,$runId,$firstStop,$input,$key,'"2"');
check($replay==$body,'final deposit replay is idempotent');
check((int)$runtime->query("SELECT count(*) FROM compartment_claims WHERE package_id={$packageIds[0]} AND compartment_id=$door AND state='HELD'")->fetchColumn()===1,'only one compartment claim created');
check((int)$runtime->query("SELECT count(*) FROM device_commands WHERE command_uuid='{$body['command_id']}' AND status='PENDING'")->fetchColumn()===1,'command remains pending for enrolled device');
check($runtime->query("SELECT status FROM terminal_pairing_sessions WHERE id=$pairing")->fetchColumn()==='CONSUMED','pairing consumed atomically');
check($runtime->query("SELECT state||':'||custodian_type FROM packages WHERE id={$packageIds[0]}")->fetchColumn()==='OUTBOUND_CUSTODY:DRIVER','preparation retains driver custody');
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,$input,Secrets::uuid(),'"2"'),409,'second deposit session refused pending reconciliation');
$pairing2=insertId($runtime,"INSERT INTO terminal_pairing_sessions(scene_uuid,device_id,actor_user_id,workflow,status,expires_at)
    VALUES (?,?,?,'FINAL_DEPOSIT','APPROVED',now()+interval '10 minutes')",[uuid(),$device,$driverUser]);
$secondInput=['package_id'=>$packageIds[1],'pairing_id'=>$pairing2,'label_payload'=>$labelTokens[1],
    'expected_package_version'=>(int)$runtime->query("SELECT version FROM packages WHERE id={$packageIds[1]}")->fetchColumn(),'expected_revision'=>2];
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,$secondInput,Secrets::uuid(),'"2"'),409,'reserved last compartment cannot be double allocated');
check((int)$runtime->query("SELECT count(*) FROM custody_events WHERE package_id={$packageIds[0]}")->fetchColumn()===1,'preparation emits no new custody event');

echo "\nFinal deposit preparation test suite complete.\n";
