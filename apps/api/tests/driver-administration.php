<?php
declare(strict_types=1);

use ZpxAdmin\DriverAdministration;
use Zpx\Custody\PickupOffers;
use Zpx\Identity\Secrets;

$previousOrg=getenv('ZPX_ORGANIZATION_ID'); putenv('ZPX_ORGANIZATION_ID='.$reviewOrg);
try {
    $adminDrivers=new DriverAdministration($runtime,new Secrets());
    $items=$adminDrivers->list($adminUser)['items'];
    check(in_array($driverId,array_column($items,'driver_id'),true) && !in_array($foreignId,array_column($items,'driver_id'),true),
        'driver administration list is network scoped');
    failsIdentity(fn()=>$adminDrivers->list($applicant),403,'driver cannot inspect admin driver list');
    failsIdentity(fn()=>$adminDrivers->transition($adminUser,$driverId,['action'=>'SUSPEND','reason'=>str_repeat(' ',10),'expected_version'=>1],Secrets::uuid()),422,
        'blank suspension reason is rejected before database write');
    failsIdentity(fn()=>$adminDrivers->transition($adminUser,$foreignId,['action'=>'SUSPEND','reason'=>'Synthetic safety review','expected_version'=>0],Secrets::uuid()),404,
        'foreign driver cannot be suspended');
    $hubLocation=insertId($runtime,"INSERT INTO locations(organization_id,code,name,kind,address_text,status) VALUES (?,?,'Synthetic driver hub','HUB','Synthetic','ACTIVE')",[$reviewOrg,uuid()]);
    $origin=insertId($runtime,"INSERT INTO locations(organization_id,code,name,kind,address_text,status) VALUES (?,?,'Synthetic driver origin','LOCKER','Synthetic','ACTIVE')",[$reviewOrg,uuid()]);
    $hub=insertId($runtime,"INSERT INTO hubs(location_id,status) VALUES (?,'ACTIVE')",[$hubLocation]);
    $vehicle=insertId($runtime,"INSERT INTO vehicles(organization_id,code,max_weight_g,max_volume_mm3,max_packages) VALUES (?,?,10000,100000000,20)",[$reviewOrg,uuid()]);
    $run=insertId($runtime,"INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,planned_start,planned_end) VALUES (?,?,?,?,'INBOUND','IN_PROGRESS',now(),now()+interval '2 hours')",[$reviewOrg,$hub,$driverId,$vehicle]);
    $runtime->prepare("INSERT INTO driver_availability(driver_id,status,updated_at) VALUES (?,'AVAILABLE',now())")->execute([$driverId]);
    $offer=insertId($runtime,"INSERT INTO driver_offers(driver_id,origin_location_id,hub_id,status,expires_at) VALUES (?,?,?,'OFFERED',now()+interval '15 minutes')",[$driverId,$origin,$hub]);
    $key=Secrets::uuid();
    $suspended=$adminDrivers->transition($adminUser,$driverId,['action'=>'SUSPEND','reason'=>'Synthetic safety review','expected_version'=>1],$key);
    check($suspended['status']==='SUSPENDED' && $suspended['version']===2,'driver suspension advances version');
    check($adminDrivers->transition($adminUser,$driverId,['action'=>'SUSPEND','reason'=>'Synthetic safety review','expected_version'=>1],$key)==$suspended,
        'driver suspension retry is idempotent');
    check($runtime->query("SELECT status FROM driver_offers WHERE id=$offer")->fetchColumn()==='CANCELLED'
        && $runtime->query("SELECT status FROM driver_availability WHERE driver_id=$driverId")->fetchColumn()==='OFFLINE'
        && $runtime->query("SELECT state FROM route_runs WHERE id=$run")->fetchColumn()==='IN_PROGRESS',
        'suspension cancels offered work and leaves assigned custody intact');
    $detail=$adminDrivers->detail($adminUser,$driverId);
    check($detail['driver']['status']==='SUSPENDED' && in_array($run,array_column($detail['runs'],'run_id'),true)
        && in_array('DRIVER_SUSPENDED',array_column($detail['activity'],'action'),true)
        && !str_contains(json_encode($detail,JSON_THROW_ON_ERROR),'Synthetic safety review'),
        'driver detail keeps assigned run visible without exposing audit reason');
    failsIdentity(fn()=>$adminDrivers->detail($adminUser,$foreignId),404,'foreign driver detail is hidden');
    failsIdentity(fn()=>$adminDrivers->detail($applicant,$driverId),403,'driver cannot inspect admin detail');
    failsIdentity(fn()=>(new PickupOffers($runtime,new Secrets()))->refresh($applicant),403,'suspended driver cannot request new offers');
    failsIdentity(fn()=>$adminDrivers->transition($adminUser,$driverId,['action'=>'REACTIVATE','reason'=>'Synthetic review cleared','expected_version'=>1],Secrets::uuid()),412,
        'stale driver version is rejected');
    $headers=['x-csrf-token'=>(new Secrets())->digest('csrf',$browserToken),'idempotency-key'=>Secrets::uuid()];
    [$response,$body]=identityHttp('GET',$base.'/admin/drivers',[],[],['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===200 && in_array($driverId,array_column($body['items'],'driver_id'),true),'HTTP driver directory is scoped');
    [$response,$body]=identityHttp('GET',$base.'/admin/drivers/'.$driverId,[],[],['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===200 && $body['driver']['driver_id']===$driverId,'HTTP driver detail is available');
    [$response,$body]=identityHttp('GET',$base.'/admin/drivers/'.$foreignId,[],[],['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===404,'HTTP foreign driver detail is hidden');
    $detailRequest=new think\Request();
    $detailRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/drivers/'.$driverId,
        'PATH_INFO'=>'/admin/drivers/'.$driverId,'HTTP_HOST'=>'localhost:8000']);
    $detailRequest->withCookie(['zpx_delivery_session'=>$browserToken]);
    $detailApp=new think\App(dirname(__DIR__)); $detailApp->debug(false);
    $detailResponse=$detailApp->http->run($detailRequest);
    check($detailResponse->getCode()===200 && str_contains($detailResponse->getContent(),'Recent assignments')
        && str_contains($detailResponse->getContent(),'Approval recorded')
        && str_contains($detailResponse->getContent(),'Earnings ledger')
        && str_contains($detailResponse->getContent(),'SUSPENDED'),
        'driver HTML detail renders assigned run and suspended status');
    $detailApp->http->end($detailResponse);
    [$response,$body]=identityHttp('POST',$base.'/admin/drivers/'.$driverId.'/transitions',
        ['action'=>'REACTIVATE','reason'=>'Synthetic review cleared'],$headers,['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===428,'HTTP driver transition requires If-Match version');
    [$response,$body]=identityHttp('POST',$base.'/admin/drivers/'.$driverId.'/transitions',
        ['action'=>'REACTIVATE','reason'=>'Synthetic review cleared'],$headers+['if-match'=>'"2"'],['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===200 && $body['status']==='ACTIVE' && $body['version']===3,'HTTP reactivation checks version and succeeds');
    check($runtime->query("SELECT status FROM driver_availability WHERE driver_id=$driverId")->fetchColumn()==='OFFLINE',
        'reactivated driver must opt in to offers again');
} finally { putenv($previousOrg===false?'ZPX_ORGANIZATION_ID':'ZPX_ORGANIZATION_ID='.$previousOrg); }
