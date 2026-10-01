<?php
declare(strict_types=1);

function signedDeviceHeaders(string $keyId,string $secret,?string $nonce=null,?int $timestamp=null,string $path='/api/delivery/v1/devices/me/commands',string $method='GET',array $input=[]): array {
    $nonce ??= uuid(); $timestamp ??= time();
    $canonical=$method."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256',json_encode($input ?: new stdClass(),JSON_THROW_ON_ERROR));
    return ['x-device-key-id'=>$keyId,'x-device-timestamp'=>(string)$timestamp,'x-device-nonce'=>$nonce,
        'x-device-signature'=>base64_encode(sodium_crypto_sign_detached($canonical,$secret))];
}
$path=$base.'/devices/me/commands';
[$response,$body]=identityHttp('GET',$path);
check($response->getCode()===401,'device command poll requires independent credential');
$headers=signedDeviceHeaders($deviceKeyId,$deviceSecret);
$bad=$headers; $bad['x-device-signature']=base64_encode(str_repeat('x',SODIUM_CRYPTO_SIGN_BYTES));
[$response,$body]=identityHttp('GET',$path,[],$bad);
check($response->getCode()===401,'device command poll rejects forged signature');
[$response,$body]=identityHttp('GET',$path,[],signedDeviceHeaders($deviceKeyId,$deviceSecret,null,time()-120));
check($response->getCode()===401,'device command poll rejects stale timestamp');
[$response,$body]=identityHttp('GET',$path,[],$headers);
check($response->getCode()===200 && count($body['items'])===1 && $body['items'][0]['command_id']===$replay['command_id'],
    'enrolled destination device receives only its pending command');
check($body['items'][0]['action']==='OPEN' && $body['items'][0]['address']===['locker_id'=>(string)$locker,'board_address'=>1,'door_address'=>1]
    && $body['items'][0]['ownership_generation']===1,'device command includes owned physical address and generation');
check((int)$runtime->query("SELECT count(*) FROM device_commands WHERE command_uuid='{$replay['command_id']}' AND dispatched_at IS NOT NULL AND payload_hash IS NOT NULL")->fetchColumn()===1,
    'first authorized poll freezes command payload and records dispatch');
[$response,$body]=identityHttp('GET',$path,[],$headers);
check($response->getCode()===409 && $body['code']==='DEVICE_REQUEST_REPLAY','signed request nonce cannot be replayed');
[$response,$body]=identityHttp('GET',$path,[],signedDeviceHeaders($deviceKeyId,$deviceSecret));
check($response->getCode()===200 && count($body['items'])===1,'fresh signed retry returns same stable command');
$runtime->exec("UPDATE packages SET version=version+1 WHERE id={$packageIds[0]}");
[$response,$body]=identityHttp('GET',$path,[],signedDeviceHeaders($deviceKeyId,$deviceSecret));
check($response->getCode()===200 && $body['items']===[],'stale parcel version suppresses physical command');
$runtime->exec("UPDATE packages SET version=version-1 WHERE id={$packageIds[0]}");
$runtime->exec("UPDATE compartments SET door_address=2 WHERE id=$door");
[$response,$body]=identityHttp('GET',$path,[],signedDeviceHeaders($deviceKeyId,$deviceSecret));
check($response->getCode()===409 && $body['code']==='COMMAND_PAYLOAD_CHANGED','changed electrical address blocks command replay');
$runtime->exec("UPDATE compartments SET door_address=1 WHERE id=$door");
$owner->exec("UPDATE compartment_ownership SET owner='FROZEN' WHERE compartment_id=$door");
[$response,$body]=identityHttp('GET',$path,[],signedDeviceHeaders($deviceKeyId,$deviceSecret));
check($response->getCode()===200 && $body['items']===[],'frozen ownership suppresses physical command');
$owner->exec("UPDATE compartment_ownership SET owner='DELIVERY' WHERE compartment_id=$door");
check($runtime->query("SELECT state||':'||custodian_type FROM packages WHERE id={$packageIds[0]}")->fetchColumn()==='OUTBOUND_CUSTODY:DRIVER',
    'device command polling never transfers custody');

$configPath=$base.'/devices/me/cabinet-config';
[$response,$body]=identityHttp('GET',$configPath);
check($response->getCode()===401,'cabinet config requires signed device identity');
[$response,$body]=identityHttp('POST',$configPath);
check($response->getCode()===405,'cabinet config is read only');
[$response,$body]=identityHttp('GET',$configPath,[],signedDeviceHeaders($deviceKeyId,$deviceSecret));
check($response->getCode()===401,'command signature cannot authorize cabinet config');
$signedConfig=static fn() => signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,'/api/delivery/v1/devices/me/cabinet-config');
$modelsPath=$base.'/devices/me/box-models';
$signedModels=static fn() => signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,'/api/delivery/v1/devices/me/box-models');
[$response,$body]=identityHttp('GET',$modelsPath,[],$signedModels());
check($response->getCode()===404 && $body['code']==='CABINET_CONFIG_UNAVAILABLE','unbound cabinet cannot enumerate Delivery box models');
[$response,$body]=identityHttp('GET',$configPath,[],$signedConfig());
check($response->getCode()===404 && $body['code']==='CABINET_CONFIG_UNAVAILABLE','unbound cabinet has no terminal config');
$orgId=(string)$runtime->query("SELECT organization_id FROM locations WHERE id=$destLocation")->fetchColumn();
$boxModel=(string)$runtime->query("INSERT INTO cabinet_box_model(organization_id,code,version,model_name,width_mm,height_mm,depth_mm,max_weight_g,dimensions_source_unit)
    VALUES ($orgId,'TERMINAL-TEST',1,'Small',200,200,200,1000,'MM') RETURNING model_id")->fetchColumn();
$bodyModel=(string)$runtime->query("INSERT INTO cabinet_body_model(organization_id,code,version,model_name,status)
    VALUES ($orgId,'TERMINAL-TEST',1,'sub','READY') RETURNING model_id")->fetchColumn();
$cabinet=(string)$runtime->query("INSERT INTO cabinet(organization_id,cabinet_name,status,bound_location_id,bound_locker_id,bind_key,bind_hash)
    VALUES ($orgId,'Terminal test','BOUND',$destLocation,$locker,'terminal-test-key',repeat('a',64)) RETURNING cabinet_id")->fetchColumn();
$bodyId=(string)$runtime->query("INSERT INTO cabinet_body(cabinet_id,body_model_id,body_name,sequence,display_sequence,addr,protocol_profile)
    VALUES ($cabinet,$bodyModel,'Test body','1',1,1,'SIMULATED_24') RETURNING body_id")->fetchColumn();
$boxId=(string)$runtime->query("INSERT INTO cabinet_box(cabinet_id,body_id,box_model_id,\"row\",\"column\",addr,compartment_id)
    VALUES ($cabinet,$bodyId,$boxModel,1,1,1,$door) RETURNING box_id")->fetchColumn();
$runtime->exec("UPDATE controller_boards SET protocol_profile='SIMULATED_24' WHERE id=$board");
[$response,$body]=identityHttp('GET',$configPath,[],$signedConfig());
check($response->getCode()===200 && $body['boxConfig']['cabinetId']===$cabinet
    && $body['boxConfig']['cabinets'][0]['bodyId']===$bodyId
    && $body['boxConfig']['cabinets'][0]['lockAddr']===1
    && $body['boxConfig']['cabinets'][0]['boxes'][0]['boxId']===$boxId
    && $body['boxConfig']['cabinets'][0]['boxes'][0]['boxAddr']===1
    && $body['boxConfig']['cabinets'][0]['boxes'][0]['isAllocable']==='0'
    && $body['boxConfig']['cabinets'][0]['boxes'][0]['dimensionsMm']===['width'=>200,'height'=>200,'depth'=>200]
    && $body['boxModels'][0]['availableCount']===0,
    'signed cabinet config preserves mapped IDs and addresses without offering capacity');
$configModels=$body['boxModels']; $configRevision=$body['revision'];
check(in_array($runtime->query("SELECT status IS NULL AND blocked IS NULL FROM cabinet_box WHERE box_id=$boxId")->fetchColumn(),[true,'t','1'],true),
    'reading terminal config does not mark a cabinet box available or alter legacy flags');
[$response,$body]=identityHttp('POST',$modelsPath);
check($response->getCode()===405,'terminal box models are read only');
[$response,$body]=identityHttp('GET',$modelsPath,[],$signedConfig());
check($response->getCode()===401,'config signature cannot authorize box model list');
[$response,$body]=identityHttp('GET',$modelsPath,[],$signedModels());
check($response->getCode()===200 && $body['revision']===$configRevision
    && $body['items']===$configModels,
    'signed terminal model list uses the same cabinet revision without exposing unverified capacity');
[$response,$body]=identityHttp('GET',$modelsPath,[],$signedModels());
check($response->getCode()===200,'new signed model request is allowed');
$previousEnv=getenv('APP_ENV');
try {
    putenv('APP_ENV=production');
    [$response,$body]=identityHttp('GET',$configPath,[],$signedConfig());
    check($response->getCode()===409 && $body['code']==='CABINET_PROFILE_UNVERIFIED',
        'simulator cabinet profile is never published as production terminal configuration');
} finally { putenv($previousEnv===false ? 'APP_ENV' : 'APP_ENV='.$previousEnv); }
$runtime->exec("UPDATE cabinet_box SET addr=2 WHERE box_id=$boxId");
[$response,$body]=identityHttp('GET',$configPath,[],$signedConfig());
check($response->getCode()===409 && $body['code']==='CABINET_ADDRESS_MISMATCH','changed source door address blocks config publication');
$runtime->exec("UPDATE cabinet_box SET addr=1 WHERE box_id=$boxId");
$runtime->exec("UPDATE controller_boards SET protocol_profile='TEST_PROFILE' WHERE id=$board");

$eventPath=$base.'/devices/me/command-events';
$signEvent=static fn(array $input) => signedDeviceHeaders($deviceKeyId,$deviceSecret,null,null,
    '/api/delivery/v1/devices/me/command-events','POST',$input);
$journal=['command_id'=>$replay['command_id'],'event_id'=>uuid(),'event_type'=>'DISPATCH_RECORDED'];
[$response,$body]=identityHttp('GET',$eventPath);
check($response->getCode()===405,'command event route accepts POST only');
[$response,$body]=identityHttp('POST',$eventPath,$journal);
check($response->getCode()===401,'command observations require enrolled signed device');
$open=['command_id'=>$replay['command_id'],'event_id'=>uuid(),'event_type'=>'OPEN_OBSERVED'];
[$response,$body]=identityHttp('POST',$eventPath,$open,$signEvent($open));
check($response->getCode()===409 && $body['code']==='EVENT_SEQUENCE_INVALID','open cannot precede terminal dispatch journal');
$stale=$journal;
$runtime->exec("UPDATE packages SET version=version+1 WHERE id={$packageIds[0]}");
[$response,$body]=identityHttp('POST',$eventPath,$stale,$signEvent($stale));
check($response->getCode()===409 && $body['code']==='COMMAND_STALE','changed package blocks dispatch journal');
$runtime->exec("UPDATE packages SET version=version-1 WHERE id={$packageIds[0]}");
$runtime->exec("UPDATE locations SET site_mode='HYBRID_PARTITIONED' WHERE id=$destLocation");
[$response,$body]=identityHttp('POST',$eventPath,$journal,$signEvent($journal));
check($response->getCode()===409 && $body['code']==='COMMAND_STALE','shared-site switch blocks terminal dispatch');
$runtime->exec("UPDATE locations SET site_mode='DELIVERY_ONLY' WHERE id=$destLocation");
[$response,$body]=identityHttp('POST',$eventPath,$journal,$signEvent($journal));
check($response->getCode()===200 && $body['command_status']==='DISPATCH_RECORDED' && $body['custody_transferred']===false,
    'signed terminal journal records dispatch without changing custody');
[$response,$body]=identityHttp('POST',$eventPath,$journal,$signEvent($journal));
check($response->getCode()===200 && $body['replayed']===true,'same device event ID can be retried with a fresh signed request');
[$response,$body]=identityHttp('POST',$eventPath,$open,$signEvent($open));
check($response->getCode()===200 && $body['session_status']==='OPEN','signed open observation advances only locker session');
$close=['command_id'=>$replay['command_id'],'event_id'=>uuid(),'event_type'=>'CLOSE_OBSERVED'];
[$response,$body]=identityHttp('POST',$eventPath,$close,$signEvent($close));
check($response->getCode()===200 && $body['session_status']==='CLOSED' && $body['custody_transferred']===false,
    'signed close observation still does not deliver package');
check((int)$runtime->query("SELECT count(*) FROM device_events WHERE command_id=(SELECT id FROM device_commands WHERE command_uuid='{$replay['command_id']}')")->fetchColumn()===3,
    'terminal journal stores exactly one event per ordered observation');
check($runtime->query("SELECT state||':'||custodian_type FROM packages WHERE id={$packageIds[0]}")->fetchColumn()==='OUTBOUND_CUSTODY:DRIVER',
    'terminal observations do not transfer parcel custody');
$confirmPath=$base.'/runs/'.$runId.'/stops/'.$firstStop.'/final-deposits/'.$replay['session_id'].'/confirm';
$confirmHeaders=['idempotency-key'=>uuid(),'x-csrf-token'=>$driverLogin[1]['csrf_token'],'if-match'=>'"2"'];
$beforeCustody=(int)$runtime->query("SELECT count(*) FROM custody_events WHERE package_id={$packageIds[0]}")->fetchColumn();
$runtime->exec("UPDATE locations SET site_mode='HYBRID_PARTITIONED' WHERE id=$destLocation");
[$response,$body]=identityHttp('POST',$confirmPath,$confirmInput,$confirmHeaders,$driverCookies);
check($response->getCode()===404 && $body['code']==='DEPOSIT_NOT_FOUND','shared-site switch blocks final custody confirmation');
$runtime->exec("UPDATE locations SET site_mode='DELIVERY_ONLY' WHERE id=$destLocation");
[$response,$body]=identityHttp('POST',$confirmPath,$confirmInput,$confirmHeaders,$driverCookies);
check($response->getCode()===200 && $body['package_state']==='AT_DESTINATION' && $body['custody_transferred']===true
    && $body['stop_completed']===false,'driver placement attestation commits only its destination parcel');
[$response,$retryBody]=identityHttp('POST',$confirmPath,$confirmInput,$confirmHeaders,$driverCookies);
check($response->getCode()===200 && $retryBody==$body,'final deposit confirmation replays idempotently');
check($runtime->query("SELECT state||':'||custodian_type||':'||custodian_ref FROM packages WHERE id={$packageIds[0]}")->fetchColumn()==='AT_DESTINATION:LOCKER:'.$locker,
    'confirmed final deposit transfers custody to exact destination locker');
check($runtime->query("SELECT state FROM compartment_claims WHERE package_id={$packageIds[0]}")->fetchColumn()==='OCCUPIED',
    'confirmed parcel retains occupied compartment claim');
check((int)$runtime->query("SELECT count(*) FROM custody_events WHERE package_id={$packageIds[0]}")->fetchColumn()===$beforeCustody+1,
    'one final deposit custody event is recorded');
check($runtime->query("SELECT state FROM route_run_stops WHERE id=$firstStop")->fetchColumn()==='ARRIVED',
    'stop remains arrived while other parcels still require deposit');

echo "\nSigned device command test suite complete.\n";
