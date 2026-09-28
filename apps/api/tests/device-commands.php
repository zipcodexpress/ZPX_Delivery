<?php
declare(strict_types=1);

function signedDeviceHeaders(string $keyId,string $secret,?string $nonce=null,?int $timestamp=null): array {
    $nonce ??= uuid(); $timestamp ??= time();
    $canonical="GET\n/api/delivery/v1/devices/me/commands\n".$timestamp."\n".$nonce."\n".hash('sha256','{}');
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

echo "\nSigned device command test suite complete.\n";
