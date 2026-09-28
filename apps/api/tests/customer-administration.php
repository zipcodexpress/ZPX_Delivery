<?php
declare(strict_types=1);

use ZpxAdmin\CustomerManagement;
use Zpx\Identity\Secrets;

$customerAdmin=insertId($runtime,"INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?, 'Synthetic customer admin','ACTIVE')",[$shippingOrg,uuid()]);
$role=$runtime->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
$runtime->prepare('INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES (?,?,?,?)')->execute([$customerAdmin,$role,$shippingOrg,$customerAdmin]);
$previousOrg=getenv('ZPX_ORGANIZATION_ID'); putenv('ZPX_ORGANIZATION_ID='.$shippingOrg);
try {
    $adminCustomers=new CustomerManagement($runtime,new Secrets());
    $ids=array_column($adminCustomers->list($customerAdmin)['items'],'user_id');
    check(in_array($sender,$ids,true),'customer admin list includes same-network customer');
    $foreignCustomerOrg=insertId($runtime,'INSERT INTO organizations(name) VALUES (?)',['Foreign customer detail '.uuid()]);
    $foreignCustomer=insertId($runtime,"INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?, 'Foreign customer','ACTIVE')",[$foreignCustomerOrg,uuid()]);
    $customerRole=$runtime->query("SELECT id FROM roles WHERE code='CUSTOMER'")->fetchColumn();
    $runtime->prepare('INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES (?,?,?,?)')
        ->execute([$foreignCustomer,$customerRole,$foreignCustomerOrg,$foreignCustomer]);
    failsIdentity(fn()=>$adminCustomers->detail($customerAdmin,$foreignCustomer),404,'foreign customer detail is hidden');
    failsIdentity(fn()=>$adminCustomers->list($sender),403,'customer cannot inspect admin customer directory');
    failsIdentity(fn()=>$adminCustomers->detail($sender,$sender),403,'customer cannot inspect own admin detail');
    failsIdentity(fn()=>$adminCustomers->restrict($customerAdmin,$sender,str_repeat(' ',10),Secrets::uuid()),422,
        'blank restriction reason is rejected before database write');
    $key=Secrets::uuid();
    $restricted=$adminCustomers->restrict($customerAdmin,$sender,'Synthetic risk review',$key);
    check($restricted['shipping_restricted']===true,'admin creates reasoned shipping restriction');
    check($adminCustomers->restrict($customerAdmin,$sender,'Synthetic risk review',$key)===$restricted,'restriction retry is idempotent');
    failsIdentity(fn()=>$adminCustomers->restrict($customerAdmin,$sender,'Changed reason for retry',$key),409,'changed restriction payload conflicts');
    failsIdentity(fn()=>$shipping->create($sender,$input,Secrets::uuid()),403,'restricted customer cannot create a new shipment');
    check($shipping->get($sender,$sid)['shipment_id']===$sid,'restricted customer retains access to existing shipment');
    $detail=$adminCustomers->detail($customerAdmin,$sender);
    check($detail['customer']['shipping_restricted'] && in_array($sid,array_column($detail['shipments'],'shipment_id'),true)
        && str_contains($detail['customer']['masked_email'],'***@')
        && !str_contains(json_encode($detail,JSON_THROW_ON_ERROR),$senderInput['email'])
        && !str_contains(json_encode($detail,JSON_THROW_ON_ERROR),'Synthetic risk review'),
        'customer detail links existing shipments and redacts contacts and audit reasons');
    $adminToken=Secrets::token();
    $runtime->prepare('INSERT INTO auth_credentials(user_id,password_hash) VALUES (?,?)')->execute([$customerAdmin,password_hash(Secrets::token(),PASSWORD_DEFAULT)]);
    $runtime->prepare("INSERT INTO auth_sessions(user_id,session_hash,client_kind,expires_at,family_id) VALUES (?,decode(?,'hex'),'BROWSER',now()+interval '1 hour',?)")
        ->execute([$customerAdmin,hash('sha256',$adminToken),uuid()]);
    [$response,$body]=identityHttp('GET',$base.'/admin/customers',[],[],['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===200 && in_array($sender,array_column($body['items'],'user_id'),true),'HTTP customer directory is scoped and available');
    [$response,$body]=identityHttp('GET',$base.'/admin/customers/'.$sender,[],[],['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===200 && $body['customer']['user_id']===$sender,'HTTP customer detail is available');
    [$response,$body]=identityHttp('GET',$base.'/admin/customers/'.$foreignCustomer,[],[],['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===404,'HTTP foreign customer detail is hidden');
    $detailRequest=new think\Request();
    $detailRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/customers/'.$sender,
        'PATH_INFO'=>'/admin/customers/'.$sender,'HTTP_HOST'=>'localhost:8000']);
    $detailRequest->withCookie(['zpx_delivery_session'=>$adminToken]);
    $detailApp=new think\App(dirname(__DIR__)); $detailApp->debug(false);
    $detailResponse=$detailApp->http->run($detailRequest);
    check($detailResponse->getCode()===200 && str_contains($detailResponse->getContent(),'Recent shipments')
        && !str_contains($detailResponse->getContent(),$senderInput['email']),
        'customer HTML detail renders linked shipments with masked contact');
    $detailApp->http->end($detailResponse);
    [$response,$body]=identityHttp('POST',$base.'/admin/customers/'.$sender.'/restrictions/'.$restricted['restriction_id'].'/revoke',
        ['reason'=>'Synthetic review cleared'],['x-csrf-token'=>$crypto->digest('csrf',$adminToken),'idempotency-key'=>Secrets::uuid()],
        ['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===200 && $body['shipping_restricted']===false,'HTTP restriction revoke enforces current ID and CSRF');
    $restored=$body;
    check($restored['shipping_restricted']===false,'reasoned revocation restores shipping eligibility');
    $item=array_values(array_filter($adminCustomers->list($customerAdmin)['items'],fn($row)=>$row['user_id']===$sender))[0];
    check(!$item['shipping_restricted'] && str_contains($item['masked_email']??'','***@'),'customer list masks contacts and shows restored status');
} finally { putenv($previousOrg===false?'ZPX_ORGANIZATION_ID':'ZPX_ORGANIZATION_ID='.$previousOrg); }
