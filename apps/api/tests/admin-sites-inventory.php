<?php
declare(strict_types=1);

use ZpxAdmin\{PeopleEditor,SiteInventory};
use Zpx\Identity\Secrets;

$previousOrg=getenv('ZPX_ORGANIZATION_ID'); putenv('ZPX_ORGANIZATION_ID='.$shippingOrg);
try {
    $crypto=new Secrets();
    $sites=new SiteInventory($runtime,$crypto);
    $people=new PeopleEditor($runtime,$crypto);
    failsIdentity(fn()=>$sites->sites($sender),403,'customer cannot inspect sites');
    $input=['code'=>'SYNTH-APT-01','name'=>'Synthetic apartment','site_type'=>'APARTMENT',
        'address'=>['line1'=>'1 Example Lane','line2'=>'','city'=>'Austin','region'=>'TX','postal_code'=>'00000','country_code'=>'US'],
        'timezone'=>'America/Chicago','reason'=>'Synthetic site provisioning test'];
    $key=Secrets::uuid();
    $site=$sites->create($customerAdmin,$input,$key);
    check($sites->create($customerAdmin,$input,$key)===$site,'site create retry returns same site');
    failsIdentity(fn()=>$sites->create($customerAdmin,$input+['unknown'=>'x'],Secrets::uuid()),422,'site rejects unknown fields');
    failsIdentity(fn()=>$sites->create($customerAdmin,$input,Secrets::uuid()),409,'site code is unique');
    failsIdentity(fn()=>$sites->create($customerAdmin,array_replace($input,['code'=>'SYNTH-OTHER','site_type'=>'OTHER']),Secrets::uuid()),422,
        'new OTHER site requires a future description contract');
    check($sites->site($customerAdmin,$site)['site']['status']==='DRAFT','new site remains draft');
    $updated=$input['address']; $updated['line1']='2 Example Lane';
    $sites->updateDraft($customerAdmin,$site,['name'=>'Synthetic apartment revised','address'=>$updated,
        'timezone'=>'America/Chicago','version'=>'0','reason'=>'Synthetic site name correction']);
    check($sites->site($customerAdmin,$site)['site']['name']==='Synthetic apartment revised','admin edits draft site');
    $foreignSite=insertId($runtime,"INSERT INTO installation_sites(organization_id,code,name,site_type,address,timezone)
        VALUES (?,'FOREIGN-APT','Foreign apartment','APARTMENT','{}','America/Chicago')",[$foreignCustomerOrg]);
    failsIdentity(fn()=>$sites->site($customerAdmin,$foreignSite),404,'foreign site detail is hidden');
    $locationInput=['code'=>'SYNTH-APT-ENTRY','name'=>'Building entrance','address_text'=>'1 Example Lane, entrance A',
        'reason'=>'Synthetic entrance provisioning'];
    $locationKey=Secrets::uuid();
    $location=$sites->addLocation($customerAdmin,$site,$locationInput,$locationKey);
    check($sites->addLocation($customerAdmin,$site,$locationInput,$locationKey)===$location,'location create retry is idempotent');
    $detail=$sites->site($customerAdmin,$site);
    check(count($detail['locations'])===1 && $detail['locations'][0]['status']==='INACTIVE',
        'site contains inactive service location');
    failsIdentity(fn()=>$sites->reactivateLocation($customerAdmin,$site,$location,'Premature commissioning test'),409,
        'new inactive location cannot bypass commissioning');
    $locker=(string)$detail['locations'][0]['locker_id'];
    check(count($sites->locker($customerAdmin,$locker)['boxes'])===0,'new locker has no commissioned boxes');
    $sites->addBody($customerAdmin,$locker,['code'=>'BODY-A','position'=>'1','reason'=>'Synthetic body inventory setup']);
    $body=(string)$sites->locker($customerAdmin,$locker)['bodies'][0]['id'];
    $sites->addBoxModule($customerAdmin,$locker,['body_id'=>$body,'code'=>'BOX-MOD-A','position'=>'1',
        'reason'=>'Synthetic box module setup']);
    $module=(string)$sites->locker($customerAdmin,$locker)['modules'][0]['id'];
    $sites->addDraftBox($customerAdmin,$locker,['code'=>'DRAFT-S','module_id'=>$module,'width_mm'=>'250',
        'height_mm'=>'250','depth_mm'=>'350','max_weight_g'=>'3000','reason'=>'Synthetic draft box creation']);
    check($sites->locker($customerAdmin,$locker)['boxes'][0]['status']==='FROZEN',
        'new draft box is frozen without controller mapping');
    $box=insertId($runtime,"INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status)
        VALUES (?,'TEST-BOX',300,300,400,5000,'INACTIVE')",[$locker]);
    $sites->assignBox($customerAdmin,$locker,['box_id'=>$box,'module_id'=>$module,'reason'=>'Synthetic box grouping setup']);
    check((string)$sites->locker($customerAdmin,$locker)['boxes'][1]['box_module_id']===$module,
        'idle box assigned to box module without controller mapping');
    failsIdentity(fn()=>$sites->addBoxModule($customerAdmin,$locker,['body_id'=>$foreignSite,'code'=>'BAD-MOD',
        'position'=>'2','reason'=>'Cross locker module test']),404,'foreign body cannot be attached to locker');
    $sites->setOverdueDraft($customerAdmin,$site,['grace_days'=>'2','daily_cents'=>'100','cap_cents'=>'1000',
        'version'=>'1','reason'=>'Synthetic overdue policy draft']);
    check((int)$sites->site($customerAdmin,$site)['site']['overdue_daily_cents']===100,'site overdue rate saved as draft');
    $sites->setLocationOverdueDraft($customerAdmin,$site,$location,['grace_days'=>'1','daily_cents'=>'250',
        'cap_cents'=>'1500','reason'=>'Synthetic location-specific overdue draft']);
    check((int)$sites->site($customerAdmin,$site)['locations'][0]['overdue_daily_cents']===250,
        'location can override the site overdue draft rate');
    $sites->setLocationOverdueDraft($customerAdmin,$site,$location,['grace_days'=>'','daily_cents'=>'',
        'cap_cents'=>'','reason'=>'Synthetic location override reset']);
    check($sites->site($customerAdmin,$site)['locations'][0]['overdue_daily_cents']===null,
        'location override can return to site policy inheritance');
    failsIdentity(fn()=>$sites->setOverdueDraft($customerAdmin,$site,['grace_days'=>'2','daily_cents'=>'200','cap_cents'=>'1000',
        'version'=>'1','reason'=>'Stale policy update test']),412,'stale overdue draft rejected');
    $runtime->prepare("UPDATE locations SET status='ACTIVE',access_policy='{\"synthetic\":true}'::jsonb WHERE id=?")->execute([$location]);
    $shipping=new Zpx\Shipping\Service($runtime,$crypto);
    check(in_array($location,array_column($shipping->locations()['items'],'id'),true),'synthetic active location appears in shipping choices');
    failsIdentity(fn()=>$sites->addDraftBox($customerAdmin,$locker,['code'=>'UNSAFE-BOX','module_id'=>$module,
        'width_mm'=>'250','height_mm'=>'250','depth_mm'=>'350','max_weight_g'=>'3000',
        'reason'=>'Active locker guard test']),409,'active locker refuses draft box provisioning');
    $sites->deactivateLocation($customerAdmin,$site,$location,'Synthetic location safety pause');
    check($sites->site($customerAdmin,$site)['locations'][0]['status']==='INACTIVE','admin deactivates location');
    check(!in_array($location,array_column($shipping->locations()['items'],'id'),true),
        'deactivated location disappears from new shipping choices');
    failsIdentity(fn()=>$sites->deactivateLocation($customerAdmin,$site,$location,'Repeated safety pause test'),409,
        'duplicate location deactivation rejected');
    $sites->reactivateLocation($customerAdmin,$site,$location,'Synthetic location review cleared');
    check(in_array($location,array_column($shipping->locations()['items'],'id'),true),
        'previously active location can be reactivated with audit reason');
    $before=count($people->addresses($customerAdmin,$sender));
    $address=['line1'=>'22 Test Street','line2'=>'Unit 4','city'=>'Austin','region'=>'TX','postal_code'=>'00000','country_code'=>'US'];
    $people->save($customerAdmin,$sender,['address_id'=>'','kind'=>'RETURN','label'=>'Office',
        'address'=>$address,'reason'=>'Synthetic return address addition']);
    $book=$people->addresses($customerAdmin,$sender);
    check(count($book)===$before+1 && end($book)['label']==='Office','admin adds second encrypted account address');
    $primary=(string)$book[0]['id'];
    failsIdentity(fn()=>$people->archive($customerAdmin,$sender,$primary,'Cannot remove sole profile'),409,
        'sole profile address cannot be archived');
    failsIdentity(fn()=>$people->save($customerAdmin,$sender,['address_id'=>$primary,'kind'=>'BILLING',
        'label'=>'Invalid','address'=>$address,'reason'=>'Cannot replace sole profile']),409,
        'sole profile address cannot change purpose');
    $addressId=(string)end($book)['id'];
    $people->save($customerAdmin,$sender,['address_id'=>$addressId,'kind'=>'BILLING','label'=>'Billing',
        'address'=>$address,'reason'=>'Synthetic address correction']);
    $book=$people->addresses($customerAdmin,$sender);
    check(end($book)['kind']==='BILLING','admin edits existing address');
    failsIdentity(fn()=>$people->addresses($sender,$sender),403,'customer cannot use admin address reveal');
    $people->archive($customerAdmin,$sender,$addressId,'Synthetic address retirement');
    check(count($people->addresses($customerAdmin,$sender))===$before,'archived address leaves active book');
    $profile=(new Zpx\Identity\Service($runtime,$crypto))->profile($sender);
    check(count($profile['addresses'])===$before,'archived address is absent from customer profile');
    $people->rename($customerAdmin,$sender,'Synthetic edited customer','Synthetic account correction');
    check((new ZpxAdmin\CustomerManagement($runtime,$crypto))->detail($customerAdmin,$sender)['customer']['name']==='Synthetic edited customer',
        'admin name change appears in customer detail');
    $pageRequest=new think\Request();
    $pageRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/sites/'.$site,'PATH_INFO'=>'/admin/sites/'.$site,'HTTP_HOST'=>'localhost:8000']);
    $pageRequest->withCookie(['zpx_delivery_session'=>$adminToken]);
    $pageApp=new think\App(dirname(__DIR__)); $pageApp->debug(false);
    $pageResponse=$pageApp->http->run($pageRequest);
    check($pageResponse->getCode()===200 && str_contains($pageResponse->getContent(),'Synthetic apartment revised')
        && str_contains($pageResponse->getContent(),'Add locker service location'),'site detail renders location form');
    $pageApp->http->end($pageResponse);
} finally { putenv($previousOrg===false?'ZPX_ORGANIZATION_ID':'ZPX_ORGANIZATION_ID='.$previousOrg); }
