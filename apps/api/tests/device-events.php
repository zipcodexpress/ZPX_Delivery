<?php
declare(strict_types=1);

function signedDeviceEventHeaders(string $keyId,string $secret,array $body): array {
    $timestamp=(string)time(); $nonce=uuid();
    $canonical="POST\n/api/delivery/v1/devices/me/events\n".$timestamp."\n".$nonce."\n".hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
    return ['x-device-key-id'=>$keyId,'x-device-timestamp'=>$timestamp,'x-device-nonce'=>$nonce,
        'x-device-signature'=>base64_encode(sodium_crypto_sign_detached($canonical,$secret))];
}
$eventPath=$base.'/devices/me/events';
$commandPayload=json_decode((string)$runtime->query("SELECT command_payload FROM device_commands WHERE command_uuid='{$replay['command_id']}'")->fetchColumn(),true,24,JSON_THROW_ON_ERROR);
$boot=uuid();
$observation=['event_id'=>uuid(),'boot_id'=>$boot,'sequence'=>1,'command_id'=>$replay['command_id'],
    'session_id'=>$replay['session_id'],'event_type'=>'DISPATCH_RECORDED',
    'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'address'=>$commandPayload['address'],
    'ownership_generation'=>$commandPayload['ownership_generation']];
[$response,$receipt]=identityHttp('POST',$eventPath,$observation);
check($response->getCode()===401,'device telemetry requires signed enrollment');
[$response,$receipt]=identityHttp('POST',$eventPath,$observation,signedDeviceEventHeaders($deviceKeyId,$deviceSecret,$observation));
check($response->getCode()===200 && $receipt['result']==='RECORDED' && $receipt['custody_transferred']===false,
    'signed dispatch observation journals telemetry without delivery');
[$response,$duplicate]=identityHttp('POST',$eventPath,$observation,signedDeviceEventHeaders($deviceKeyId,$deviceSecret,$observation));
check($response->getCode()===200 && $duplicate['result']==='DUPLICATE','same device event ID and content is replay-safe');
$changed=$observation; $changed['event_type']='UNKNOWN';
[$response,$error]=identityHttp('POST',$eventPath,$changed,signedDeviceEventHeaders($deviceKeyId,$deviceSecret,$changed));
check($response->getCode()===409 && $error['code']==='DEVICE_EVENT_CONFLICT','same event ID with different telemetry is refused');
$open=$observation; $open['event_id']=uuid(); $open['sequence']=2; $open['event_type']='OPEN_OBSERVED'; $open['frame_hash']=hash('sha256','synthetic-open-frame');
[$response,$receipt]=identityHttp('POST',$eventPath,$open,signedDeviceEventHeaders($deviceKeyId,$deviceSecret,$open));
check($response->getCode()===200 && $receipt['result']==='RECORDED','signed open observation is retained for later correlation');
$close=$open; $close['event_id']=uuid(); $close['sequence']=3; $close['event_type']='CLOSE_OBSERVED'; $close['frame_hash']=hash('sha256','synthetic-close-frame');
[$response,$receipt]=identityHttp('POST',$eventPath,$close,signedDeviceEventHeaders($deviceKeyId,$deviceSecret,$close));
check($response->getCode()===200 && $receipt['result']==='RECORDED','signed close observation is retained without automatic confirmation');
$sequenceConflict=$close; $sequenceConflict['event_id']=uuid();
[$response,$error]=identityHttp('POST',$eventPath,$sequenceConflict,signedDeviceEventHeaders($deviceKeyId,$deviceSecret,$sequenceConflict));
check($response->getCode()===409 && $error['code']==='DEVICE_SEQUENCE_CONFLICT','boot sequence cannot be reused for another event');
$wrongAddress=$close; $wrongAddress['event_id']=uuid(); $wrongAddress['sequence']=4; $wrongAddress['address']['door_address']=9;
[$response,$error]=identityHttp('POST',$eventPath,$wrongAddress,signedDeviceEventHeaders($deviceKeyId,$deviceSecret,$wrongAddress));
check($response->getCode()===409 && $error['code']==='COMMAND_CONTEXT_MISMATCH','telemetry at wrong electrical address is refused');
$runtime->exec("UPDATE device_commands SET expires_at=now()-interval '1 minute' WHERE command_uuid='{$replay['command_id']}'");
$late=$close; $late['event_id']=uuid(); $late['sequence']=4; $late['event_type']='UNKNOWN'; unset($late['frame_hash']);
[$response,$receipt]=identityHttp('POST',$eventPath,$late,signedDeviceEventHeaders($deviceKeyId,$deviceSecret,$late));
check($response->getCode()===200 && $receipt['result']==='RECORDED','late signed observation is retained after command expiry');
check((int)$runtime->query("SELECT count(*) FROM device_events WHERE command_id=(SELECT id FROM device_commands WHERE command_uuid='{$replay['command_id']}')")->fetchColumn()===4,
    'only four unique correlated terminal observations are retained');
check($runtime->query("SELECT state||':'||custodian_type FROM packages WHERE id={$packageIds[0]}")->fetchColumn()==='OUTBOUND_CUSTODY:DRIVER'
    && $runtime->query("SELECT status FROM locker_sessions WHERE id={$replay['session_id']}")->fetchColumn()==='READY',
    'signed open and close observations alone do not complete deposit');

echo "\nSigned device event test suite complete.\n";
