<?php
declare(strict_types=1);

use ZpxAdmin\{CustomerManagement,DriverAdministration,ShipmentOverview,SiteInventory,PartnerRegistry};
use Zpx\Identity\Secrets;

$previousOrg=getenv('ZPX_ORGANIZATION_ID'); putenv('ZPX_ORGANIZATION_ID='.$shippingOrg);
try {
    $crypto=new Secrets();
    $journey=new ShipmentOverview($runtime,$crypto);
    $detail=$journey->detail($customerAdmin,$sid);
    check($detail['shipment']['shipment_id']===$sid && $detail['parties']['sender_user_id']===$sender
        && is_array($detail['tracking']['events']) && is_array($detail['payments']),
        'admin shipment detail joins existing shipping, custody and payment records');
    failsIdentity(fn()=>$journey->detail($sender,$sid),403,'customer cannot inspect admin shipment lifecycle');
    $customer=(new CustomerManagement($runtime,$crypto))->detail($customerAdmin,$sender);
    check((int)$customer['totals']['shipment_count']>=1 && is_array($customer['payments']),
        'customer view includes recorded shipping and payment history');

    $registry=new PartnerRegistry($runtime,$crypto);
    $host=$registry->create($customerAdmin,['code'=>'TEST-HOST-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8)),'display_name'=>'Synthetic property host',
        'roles'=>['HOST','SITE_OWNER'],'reason'=>'Synthetic site relationship test'],Secrets::uuid())['partner']['partner_id'];
    $sites=new SiteInventory($runtime,$crypto);
    $siteId=$sites->create($customerAdmin,['code'=>'TEST-SITE-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8)),'name'=>'Synthetic host site','site_type'=>'APARTMENT',
        'address'=>['line1'=>'1 Test Road','line2'=>'','city'=>'Austin','region'=>'TX','postal_code'=>'00000','country_code'=>'US'],
        'timezone'=>'America/Chicago','reason'=>'Synthetic site relationship test'],Secrets::uuid());
    failsIdentity(fn()=>$sites->setRelationship($customerAdmin,$siteId,['owner_partner_id'=>'','host_partner_id'=>$foreignPartner,
        'contract_reference'=>'TEST-001','starts_on'=>'2026-10-01','ends_on'=>'','version'=>'0',
        'reason'=>'Cross network partner rejection']),422,'site rejects foreign host');
    failsIdentity(fn()=>$sites->setRelationship($customerAdmin,$siteId,['owner_partner_id'=>$foreignPartner,'host_partner_id'=>'',
        'contract_reference'=>'TEST-001','starts_on'=>'2026-10-01','ends_on'=>'','version'=>'0',
        'reason'=>'Cross network property owner rejection']),422,'site rejects foreign owner');
    $sites->setRelationship($customerAdmin,$siteId,['owner_partner_id'=>$host,'host_partner_id'=>$host,'contract_reference'=>'TEST-001',
        'starts_on'=>'2026-10-01','ends_on'=>'','version'=>'0','reason'=>'Synthetic host assignment']);
    check((string)$sites->site($customerAdmin,$siteId)['site']['host_partner_id']===$host
        && (string)$sites->site($customerAdmin,$siteId)['site']['owner_partner_id']===$host,
        'site stores separate explicit owner and host relationships with contract dates');
    $sites->addContact($customerAdmin,$siteId,['name'=>'Synthetic manager','role_title'=>'Property manager',
        'email'=>'manager@example.test','phone'=>'','role_code'=>'PROPERTY_MANAGER','is_primary'=>'1',
        'reason'=>'Synthetic site contact setup']);
    $contact=$sites->site($customerAdmin,$siteId)['contacts'][0];
    check($contact['email']==='manager@example.test' && !array_key_exists('email_ciphertext',$contact),
        'site contact is readable to admin without exposing ciphertext');
    failsIdentity(fn()=>$sites->addContact($customerAdmin,$siteId,['name'=>'Second manager','role_title'=>'',
        'email'=>'second@example.test','phone'=>'','role_code'=>'PROPERTY_MANAGER','is_primary'=>'1',
        'reason'=>'Duplicate primary contact test']),409,'site permits one current primary per role');
    $sites->archiveContact($customerAdmin,$siteId,(string)$contact['id'],'Synthetic contact replacement');
    check($sites->site($customerAdmin,$siteId)['contacts'][0]['ends_on']!==null,'site contact can be archived');
    failsIdentity(fn()=>$sites->lockers($sender),403,'customer cannot inspect locker inventory');
    foreach (['/admin/shipments/'.$sid=>'Package lifecycle',
        '/admin/customers/'.$sender=>'Shipping and payment overview',
        '/admin/sites/'.$siteId=>'Site contacts',
        '/admin/lockers'=>'Locker inventory'] as $path=>$expected) {
        $request=new think\Request();
        $request->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>$path,'PATH_INFO'=>$path,'HTTP_HOST'=>'localhost:8000']);
        $request->withCookie(['zpx_delivery_session'=>$adminToken]);
        $app=new think\App(dirname(__DIR__)); $app->debug(false);
        $response=$app->http->run($request);
        check($response->getCode()===200 && str_contains($response->getContent(),$expected),
            'admin HTML renders '.$path);
        $app->http->end($response);
    }
} finally { putenv($previousOrg===false?'ZPX_ORGANIZATION_ID':'ZPX_ORGANIZATION_ID='.$previousOrg); }
