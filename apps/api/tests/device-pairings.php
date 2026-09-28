<?php
declare(strict_types=1);
use Zpx\Custody\DevicePairings;
use Zpx\Custody\FinalDeposit;
use Zpx\Identity\Secrets;

$pairings=new DevicePairings($runtime,$crypto);
$pairBody=['workflow'=>'FINAL_DEPOSIT'];
$pairPath=$base.'/devices/me/pairings';
function signedPairCreate(string $keyId,string $secret,array $body): array {
    $timestamp=(string)time(); $nonce=uuid();
    $canonical="POST\n/api/delivery/v1/devices/me/pairings\n".$timestamp."\n".$nonce."\n".hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
    return ['x-device-key-id'=>$keyId,'x-device-timestamp'=>$timestamp,'x-device-nonce'=>$nonce,
        'x-device-signature'=>base64_encode(sodium_crypto_sign_detached($canonical,$secret))];
}
[$response,$body]=identityHttp('POST',$pairPath,$pairBody);
check($response->getCode()===401,'terminal pairing creation requires signed device identity');
[$response,$scene]=identityHttp('POST',$pairPath,$pairBody,signedPairCreate($deviceKeyId,$deviceSecret,$pairBody));
check($response->getCode()===201 && $scene['status']==='PENDING' && $scene['location_id']===(string)$destLocation,
    'enrolled destination terminal creates site-bound pairing scene');
$scenePath=$base.'/runs/'.$runId.'/stops/'.$firstStop.'/pairings/'.$scene['scene_uuid'];
check($pairings->preview($driverUser,$scene['scene_uuid'],$runId,$firstStop)['location_name']==='Dest Locker',
    'driver pairing preview service resolves arrived destination');
[$response,$preview]=identityHttp('GET',$scenePath,[],[],$driverCookies);
check($response->getCode()===200 && $preview['location_name']==='Dest Locker' && $preview['workflow']==='FINAL_DEPOSIT',
    'driver previews exact site and action before approval');
failsIdentity(fn()=> $pairings->preview($staffUser,$scene['scene_uuid'],$runId,$firstStop),403,'hub staff cannot preview driver pairing');
failsIdentity(fn()=> $pairings->preview($driverUser,$scene['scene_uuid'],$runId,$secondStop),404,'pairing cannot move to unarrived stop');
$approvalKey=Secrets::uuid();
[$response,$approved]=identityHttp('POST',$scenePath.'/approve',['expected_revision'=>2],
    ['idempotency-key'=>$approvalKey,'x-csrf-token'=>$driverLogin[1]['csrf_token'],'if-match'=>'"2"'],$driverCookies);
check($response->getCode()===200 && $approved['status']==='APPROVED' && $approved['pairing_id']===$scene['pairing_id'],
    'arrived driver approves site-bound scene');
[$response,$same]=identityHttp('POST',$scenePath.'/approve',['expected_revision'=>2],
    ['idempotency-key'=>$approvalKey,'x-csrf-token'=>$driverLogin[1]['csrf_token'],'if-match'=>'"2"'],$driverCookies);
check($response->getCode()===200 && $same==$approved,'driver approval replay is idempotent');
failsIdentity(fn()=> $pairings->approve($driverUser,$scene['scene_uuid'],$runId,$firstStop,['expected_revision'=>2],Secrets::uuid(),'"2"'),409,
    'scene cannot be approved twice with a new key');
$secondDoor=insertId($runtime,"INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status,controller_board_id,door_address)
    VALUES (?,'D2',200,200,200,1000,'AVAILABLE',?,2)",[$locker,$board]);
$owner->prepare("INSERT INTO compartment_ownership(compartment_id,locker_id,manifest_id,owner,generation) VALUES (?,?,?,'DELIVERY',1)")
    ->execute([$secondDoor,$locker,$ownership]);
$secondInput['pairing_id']=$scene['pairing_id'];
$prepared=(new FinalDeposit($runtime,$crypto))->prepare($driverUser,$runId,$firstStop,$secondInput,Secrets::uuid(),'"2"');
check($prepared['status']==='READY' && $prepared['custody_transferred']===false && $prepared['compartment_code']==='D2',
    'approved scene authorizes second parcel preparation without custody transfer');
check($runtime->query("SELECT status FROM terminal_pairing_sessions WHERE id={$scene['pairing_id']}")->fetchColumn()==='CONSUMED',
    'preparation consumes exact approved scene');

echo "\nDestination device pairing test suite complete.\n";
