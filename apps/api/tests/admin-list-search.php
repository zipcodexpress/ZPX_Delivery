<?php
declare(strict_types=1);

use ZpxAdmin\{CustomerManagement,DriverAdministration,History,LockerModels,LockerSetups,PartnerRegistry,SiteInventory};
use Zpx\Identity\Secrets;

$previousOrg=getenv('ZPX_ORGANIZATION_ID'); putenv('ZPX_ORGANIZATION_ID='.$shippingOrg);
try {
    $missing='NO-SUCH-ADMIN-ROW-987654';
    $crypto=new Secrets();
    $customers=new CustomerManagement($runtime,$crypto);
    $drivers=new DriverAdministration($runtime,$crypto);
    $partners=new PartnerRegistry($runtime,$crypto);
    $sites=new SiteInventory($runtime,$crypto);
    $models=new LockerModels($runtime);
    $setups=new LockerSetups($runtime);
    $routing=new Zpx\Custody\PickupRouting($runtime,$crypto);
    $recovery=new Zpx\Custody\PickupRecovery($runtime,$crypto);
    $shipping=new Zpx\Shipping\Service($runtime,$crypto);
    check($customers->list($customerAdmin,'',$missing)['items']===[]
        && $drivers->list($customerAdmin,'',$missing)['items']===[]
        && $partners->list($customerAdmin,'',$missing)['items']===[]
        && $sites->sites($customerAdmin,'',$missing)['items']===[]
        && $sites->lockers($customerAdmin,'',$missing)['items']===[]
        && $setups->listing($customerAdmin,$missing)['items']===[]
        && $routing->list($customerAdmin,$missing)['origins']===[]
        && $recovery->list($customerAdmin,$missing)['items']===[]
        && $shipping->list($customerAdmin,'operations','',$missing)['items']===[],
        'admin list searches filter database rows before list limits');
    check($models->catalog($customerAdmin,'box-models',['q'=>'No-code box model'])['boxes'][0]['name']==='No-code box model'
        && $models->catalog($customerAdmin,'body-models',['q'=>'Historical test body','status'=>'HISTORICAL'])['bodies'][0]['name']==='Historical test body'
        && count($models->catalog($customerAdmin,'body-box-layouts',['q'=>'Historical test body','status'=>'HISTORICAL'])['slots'])===1,
        'box, body and body-box views search names and source status');
    $knownSite=$runtime->query('SELECT id,code FROM installation_sites WHERE organization_id='.(int)$shippingOrg.' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    check($knownSite!==false && count($sites->sites($customerAdmin,'',$knownSite['code'])['items'])===1,
        'site search finds a specific code in the database');
    $knownSetup=$runtime->query('SELECT cabinet_id,cabinet_name FROM cabinet WHERE organization_id='.(int)$shippingOrg.' ORDER BY cabinet_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    check($knownSetup!==false && count($setups->listing($customerAdmin,$knownSetup['cabinet_name'])['items'])>=1,
        'locker set search finds a cabinet by name');
    $knownShipment=$runtime->query('SELECT sender_user_id,public_reference FROM shipments WHERE organization_id='.(int)$shippingOrg.' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $history=new History($runtime,$crypto);
    check($knownShipment!==false
        && count($history->page($customerAdmin,'customer',(string)$knownShipment['sender_user_id'],'shipments','',$knownShipment['public_reference'])['items'])>=1
        && $history->page($customerAdmin,'customer',(string)$knownShipment['sender_user_id'],'shipments','',$missing)['items']===[],
        'customer history searches references before pagination');

    $app=new think\App(dirname(__DIR__)); $app->debug(false);
    foreach (['customers','drivers','partners','sites','lockers','box-models','body-models','body-box-layouts',
        'locker-setups','pickup-routes','pickup-recovery','shipments'] as $page) {
        $request=new think\Request();
        $request->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/'.$page,
            'PATH_INFO'=>'/admin/'.$page,'HTTP_HOST'=>'localhost:8000']);
        $request->withCookie(['zpx_delivery_session'=>$adminToken]);
        $response=$app->http->run($request);
        check($response->getCode()===200 && str_contains($response->getContent(),'role="search"')
            && str_contains($response->getContent(),'name="q"'),$page.' renders an admin search form');
        $app->http->end($response);
    }
    $request=new think\Request();
    $historyUri='/admin/customers/'.$knownShipment['sender_user_id'].'/history';
    $request->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>$historyUri,
        'PATH_INFO'=>$historyUri,'HTTP_HOST'=>'localhost:8000']);
    $request->withCookie(['zpx_delivery_session'=>$adminToken]);
    $response=$app->http->run($request);
    check($response->getCode()===200 && str_contains($response->getContent(),'role="search"')
        && str_contains($response->getContent(),'name="kind"'),
        'customer history renders a search form');
    $app->http->end($response);
} finally { putenv($previousOrg===false?'ZPX_ORGANIZATION_ID':'ZPX_ORGANIZATION_ID='.$previousOrg); }
