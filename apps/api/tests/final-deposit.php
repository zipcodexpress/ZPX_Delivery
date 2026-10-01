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
$deviceKeypair=sodium_crypto_sign_keypair();
$deviceSecret=sodium_crypto_sign_secretkey($deviceKeypair);
$devicePublic=base64_encode(sodium_crypto_sign_publickey($deviceKeypair));
$deviceKeyId='test-key-'.uuid();
$runtime->prepare("INSERT INTO device_credentials(device_id,key_id,public_key,valid_from) VALUES (?,?,?,now()-interval '1 hour')")
    ->execute([$device,$deviceKeyId,$devicePublic]);
$board=insertId($runtime,"INSERT INTO controller_boards(locker_id,board_address,protocol_profile,display_sequence,serial_config) VALUES (?,1,'TEST_PROFILE',1,'{}')",[$locker]);
$door=insertId($runtime,"INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status) VALUES (?,'D1',200,200,200,1000,'AVAILABLE')",[$locker]);
$runtime->prepare('UPDATE compartments SET controller_board_id=?,door_address=1 WHERE id=?')->execute([$board,$door]);
$ownership=insertId($owner,"INSERT INTO ownership_manifests(locker_id,generation,manifest_hash,signature_reference,state,issued_by,activated_at)
    VALUES (?,1,decode(repeat('aa',32),'hex'),'test-commissioned','ACTIVE',?,now())",[$locker,$staffUser]);
$owner->prepare("INSERT INTO compartment_ownership(compartment_id,locker_id,manifest_id,owner,generation) VALUES (?,?,?,'DELIVERY',1)")
    ->execute([$door,$locker,$ownership]);
$pairInput=['workflow'=>'FINAL_DEPOSIT'];
$pairPath='/api/delivery/v1/devices/me/pairings';
$pairNonce=uuid(); $pairTime=(string)time();
$pairSignature=base64_encode(sodium_crypto_sign_detached('POST'."\n".$pairPath."\n".$pairTime."\n".$pairNonce."\n".
    hash('sha256',json_encode($pairInput,JSON_THROW_ON_ERROR)),$deviceSecret));
[$response,$pairScene]=identityHttp('POST',$pairPath,$pairInput,
    ['x-device-key-id'=>$deviceKeyId,'x-device-timestamp'=>$pairTime,'x-device-nonce'=>$pairNonce,
      'x-device-signature'=>$pairSignature,'idempotency-key'=>uuid()]);
check($response->getCode()===201 && $pairScene['status']==='PENDING','signed terminal creates short-lived final-deposit pairing scene');
$pairing=$pairScene['pairing_id'];
$approval=['workflow'=>'FINAL_DEPOSIT','location_id'=>(string)$destLocation,'scene_payload'=>$pairScene['scene_payload']];
$pairingService=new \Zpx\Custody\Pairings($runtime,$crypto);
failsIdentity(fn()=> $pairingService->approve($driverUser,$pairing,
    array_replace($approval,['scene_payload'=>'ZPXPAIR:'.$pairing.':'.uuid()]),Secrets::uuid()),404,
    'driver cannot approve an unscanned pairing scene');
failsIdentity(fn()=> $pairingService->approve($staffUser,$pairing,$approval,Secrets::uuid()),403,
    'non-driver cannot approve final-deposit pairing');
[$response,$approved]=identityHttp('POST',$base.'/pairings/'.$pairing.'/approve',$approval,
    ['idempotency-key'=>uuid(),'x-csrf-token'=>$driverLogin[1]['csrf_token']],$driverCookies);
check($response->getCode()===200 && $approved['status']==='APPROVED' && $approved['pairing_id']===$pairing,
    'assigned driver approves exact scanned site and pairing scene');
$pollPath=$base.'/devices/me/pairings/'.$pairing;
$pollNonce=uuid(); $pollTime=(string)time();
$pollSignature=base64_encode(sodium_crypto_sign_detached('GET'."\n".$pollPath."\n".$pollTime."\n".$pollNonce."\n".hash('sha256','{}'),$deviceSecret));
[$response,$polled]=identityHttp('GET',$pollPath,[],['x-device-key-id'=>$deviceKeyId,
    'x-device-timestamp'=>$pollTime,'x-device-nonce'=>$pollNonce,'x-device-signature'=>$pollSignature]);
check($response->getCode()===200 && $polled['status']==='APPROVED' && $polled['scene_payload']===$pairScene['scene_payload'],
    'signed terminal can poll only its approved pairing scene');
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
$runtime->exec("UPDATE locations SET site_mode='HYBRID_PARTITIONED' WHERE id=$destLocation");
failsIdentity(fn()=> $final->prepare($driverUser,$runId,$firstStop,$input,Secrets::uuid(),'"2"'),404,
    'shared-site destination cannot prepare Delivery physical command');
$runtime->exec("UPDATE locations SET site_mode='DELIVERY_ONLY' WHERE id=$destLocation");
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
$confirmInput=['placed'=>true,'expected_package_version'=>$version,'expected_revision'=>2];
failsIdentity(fn()=> $final->confirm($staffUser,$runId,$firstStop,$replay['session_id'],$confirmInput,Secrets::uuid(),'"2"'),403,
    'non-driver cannot attest final deposit');
failsIdentity(fn()=> $final->confirm($driverUser,$runId,$firstStop,$replay['session_id'],$confirmInput,Secrets::uuid(),'"2"'),409,
    'driver cannot confirm final deposit before terminal open and close evidence');

echo "\nFinal deposit preparation test suite complete.\n";
