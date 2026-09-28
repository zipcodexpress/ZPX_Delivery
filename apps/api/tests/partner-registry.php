<?php
declare(strict_types=1);

use ZpxAdmin\PartnerRegistry;
use Zpx\Identity\Secrets;

$previousOrg=getenv('ZPX_ORGANIZATION_ID'); putenv('ZPX_ORGANIZATION_ID='.$shippingOrg);
try {
    $registry=new PartnerRegistry($runtime,new Secrets());
    $internal=array_values(array_filter($registry->list($customerAdmin)['items'],fn($row)=>$row['kind']==='INTERNAL'));
    check(count($internal)===1 && $internal[0]['code']==='ZPX-INTERNAL' && $internal[0]['roles']===[],
        'partner registry shows one internal identity without commercial roles');
    failsIdentity(fn()=>$registry->list($sender),403,'customer cannot inspect network partner registry');
    $invalid=['code'=>'SYNTH-CARRIER','display_name'=>'Synthetic carrier','roles'=>[['CARRIER']],
        'reason'=>'Synthetic onboarding review'];
    failsIdentity(fn()=>$registry->create($customerAdmin,$invalid,Secrets::uuid()),422,'nested partner role injection rejected');
    failsIdentity(fn()=>$registry->create($customerAdmin,[
        'code'=>'SYNTH-CARRIER','display_name'=>'Synthetic carrier','roles'=>['CARRIER'],
        'legal_name'=>null,'reason'=>'Synthetic onboarding review'],Secrets::uuid()),422,
        'partner draft rejects non-text legal name');
    $input=['code'=>'SYNTH-CARRIER','display_name'=>'Synthetic carrier','roles'=>['CARRIER'],
        'reason'=>'Synthetic onboarding review'];
    $key=Secrets::uuid(); $created=$registry->create($customerAdmin,$input,$key);
    $partner=$created['partner']['partner_id'];
    check($created['partner']['status']==='DRAFT' && $created['partner']['kind']==='EXTERNAL'
        && $created['partner']['legal_name']===null && $created['partner']['roles']===['CARRIER'],
        'network admin creates external draft without inventing legal name or activation');
    check($registry->create($customerAdmin,$input,$key)==$created,'partner create retry is idempotent');
    failsIdentity(fn()=>$registry->create($customerAdmin,$input+['unsupported'=>true],Secrets::uuid()),422,
        'partner create rejects unsupported fields');
    failsIdentity(fn()=>$registry->create($customerAdmin,$input,Secrets::uuid()),409,'duplicate network partner code rejected');
    check((new ZpxAdmin\Access($runtime))->forUser($sender)['capabilities']===[],
        'partner registry role does not grant customer admin access');
    $foreignPartner=insertId($runtime,"INSERT INTO network_partners(organization_id,code,display_name,kind,status)
        VALUES (?,'FOREIGN-PARTNER','Foreign partner','EXTERNAL','DRAFT')",[$foreignCustomerOrg]);
    failsIdentity(fn()=>$registry->detail($customerAdmin,$foreignPartner),404,'foreign partner detail hidden');
    check(!in_array($foreignPartner,array_column($registry->list($customerAdmin)['items'],'partner_id'),true),
        'foreign partner omitted from network list');
    [$response,$body]=identityHttp('GET',$base.'/admin/partners',[],[],['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===200 && in_array($partner,array_column($body['items'],'partner_id'),true),
        'HTTP partner registry is network scoped');
    [$response,$body]=identityHttp('GET',$base.'/admin/partners/'.$partner,[],[],['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===200 && $body['partner']['partner_id']===$partner,'HTTP partner detail available');
    [$response,$body]=identityHttp('GET',$base.'/admin/partners/'.$foreignPartner,[],[],['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===404,'HTTP foreign partner detail hidden');
    [$response,$body]=identityHttp('POST',$base.'/admin/partners',
        ['code'=>'SYNTH-HOST','display_name'=>'Synthetic host','roles'=>['HOST'],'reason'=>'Synthetic draft host'],
        ['idempotency-key'=>Secrets::uuid()],['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===403,'HTTP partner create requires browser CSRF');
    [$response,$body]=identityHttp('POST',$base.'/admin/partners',
        ['code'=>'SYNTH-HOST','display_name'=>'Synthetic host','roles'=>['HOST'],'reason'=>'Synthetic draft host'],
        ['idempotency-key'=>Secrets::uuid(),'x-csrf-token'=>(new Secrets())->digest('csrf',$adminToken)],
        ['zpx_delivery_session'=>$adminToken]);
    check($response->getCode()===201 && $body['partner']['status']==='DRAFT'
        && $response->getHeader('Location')==='/api/delivery/v1/admin/partners/'.$body['partner']['partner_id'],
        'HTTP partner create returns draft and detail location');
    $pageRequest=new think\Request();
    $pageRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/partners','PATH_INFO'=>'/admin/partners','HTTP_HOST'=>'localhost:8000']);
    $pageRequest->withCookie(['zpx_delivery_session'=>$adminToken]);
    $pageApp=new think\App(dirname(__DIR__)); $pageApp->debug(false);
    $pageResponse=$pageApp->http->run($pageRequest);
    check($pageResponse->getCode()===200 && str_contains($pageResponse->getContent(),'Synthetic carrier')
        && str_contains($pageResponse->getContent(),'Create external partner draft'),
        'partner HTML page renders scoped registry and draft form');
    $pageApp->http->end($pageResponse);
} finally { putenv($previousOrg===false?'ZPX_ORGANIZATION_ID':'ZPX_ORGANIZATION_ID='.$previousOrg); }
