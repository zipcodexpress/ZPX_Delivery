<?php
declare(strict_types=1);

use Zpx\Driver\Service as DriverManagement;
use Zpx\Identity\Secrets;

$reviewOrg = insertId($runtime, "INSERT INTO organizations(name) VALUES (?)", ['Driver management test ' . uuid()]);
$foreignOrg = insertId($runtime, "INSERT INTO organizations(name) VALUES (?)", ['Foreign driver management test ' . uuid()]);
$adminUser = insertId($runtime, "INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?, 'Test admin','ACTIVE')", [$reviewOrg, uuid()]);
$applicant = insertId($runtime, "INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?, 'Test applicant','ACTIVE')", [$reviewOrg, uuid()]);
$rejectedApplicant = insertId($runtime, "INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?, 'Rejected applicant','ACTIVE')", [$reviewOrg, uuid()]);
$foreignApplicant = insertId($runtime, "INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?, 'Foreign applicant','ACTIVE')", [$foreignOrg, uuid()]);
$runtime->exec("INSERT INTO roles(code) VALUES ('ADMIN') ON CONFLICT(code) DO NOTHING");
$adminRole = $runtime->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
$runtime->prepare('INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES (?,?,?,?)')->execute([$adminUser,$adminRole,$reviewOrg,$adminUser]);
$addDriver = $runtime->prepare("INSERT INTO drivers(user_id,engagement_type,status) VALUES (?,'SYNTHETIC','PENDING') RETURNING id");
$addDriver->execute([$applicant]); $driverId = (string)$addDriver->fetchColumn();
$addDriver->execute([$rejectedApplicant]); $rejectedId = (string)$addDriver->fetchColumn();
$addDriver->execute([$foreignApplicant]); $foreignId = (string)$addDriver->fetchColumn();
$httpApprovedApplicant = insertId($runtime, "INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?, 'HTTP approved applicant','ACTIVE')", [$reviewOrg, uuid()]);
$httpRejectedApplicant = insertId($runtime, "INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?, 'HTTP rejected applicant','ACTIVE')", [$reviewOrg, uuid()]);
$addDriver->execute([$httpApprovedApplicant]); $httpApprovedId = (string)$addDriver->fetchColumn();
$addDriver->execute([$httpRejectedApplicant]); $httpRejectedId = (string)$addDriver->fetchColumn();
$previousOrg = getenv('ZPX_ORGANIZATION_ID');
putenv('ZPX_ORGANIZATION_ID='.$reviewOrg);
try {
    $adminAccess=(new ZpxAdmin\Access($runtime))->forUser($adminUser);
    check(in_array('admin.access',$adminAccess['capabilities'],true) && $adminAccess['scopes'] === [['kind'=>'NETWORK','id'=>$reviewOrg]],'network admin receives only its network admin scope');
    $limitedAccess=(new ZpxAdmin\Access($runtime))->forUser($applicant);
    check($limitedAccess['capabilities']===[] && $limitedAccess['scopes']===[],'ungranted driver receives no admin capabilities');
    $limitedLocation=insertId($runtime,"INSERT INTO locations(organization_id,code,name,kind,address_text,status) VALUES (?,?,'Limited hub','HUB','Synthetic','ACTIVE')",[$reviewOrg,uuid()]);
    $runtime->exec("INSERT INTO roles(code) VALUES ('HUB_STAFF') ON CONFLICT(code) DO NOTHING");
    $hubRole=$runtime->query("SELECT id FROM roles WHERE code='HUB_STAFF'")->fetchColumn();
    $runtime->prepare('INSERT INTO scoped_role_grants(user_id,role_id,organization_id,location_id,granted_by) VALUES (?,?,?,?,?)')->execute([$applicant,$hubRole,$reviewOrg,$limitedLocation,$adminUser]);
    $limitedAccess=(new ZpxAdmin\Access($runtime))->forUser($applicant);
    check($limitedAccess['capabilities']===[] && $limitedAccess['scopes'] === [['kind'=>'LOCATION','id'=>$limitedLocation]],'location grant remains scoped without admin powers');
    [$response,$body]=identityHttp('GET',$base.'/admin/access');
    check($response->getCode()===401,'admin access endpoint requires authentication');
    $management = new DriverManagement($runtime, new Secrets());
    $pending = array_column($management->listPending($adminUser)['items'], 'driver_id');
    check(in_array($driverId,$pending,true) && !in_array($foreignId,$pending,true), 'pending drivers are organization scoped');
    failsIdentity(fn()=>$management->approve($adminUser,$foreignId,Secrets::uuid()),404,'foreign driver approval denied');
    failsIdentity(fn()=>$management->reject($adminUser,$foreignId,['reason'=>'Denied by scope'],Secrets::uuid()),404,'foreign driver rejection denied');
    check($management->approve($adminUser,$driverId,Secrets::uuid())['status']==='ACTIVE','pending driver approved');
    $roleCount=$runtime->prepare("SELECT count(*) FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id WHERE g.user_id=? AND g.organization_id=? AND r.code='DRIVER' AND g.location_id IS NULL");
    $roleCount->execute([$applicant,$reviewOrg]);
    check((int)$roleCount->fetchColumn()===1,'approval grants driver access');
    check($management->reject($adminUser,$rejectedId,['reason'=>'Test rejection'],Secrets::uuid())['status']==='INACTIVE','pending driver rejected');
    check((string)$runtime->query("SELECT status FROM drivers WHERE id=$foreignId")->fetchColumn()==='PENDING','foreign applicant unchanged');

    $browserToken=Secrets::token();
    $runtime->prepare("INSERT INTO auth_credentials(user_id,password_hash) VALUES (?,?)")->execute([$adminUser,password_hash(Secrets::token(),PASSWORD_DEFAULT)]);
    $runtime->prepare("INSERT INTO auth_sessions(user_id,session_hash,client_kind,expires_at,family_id) VALUES (?,decode(?,'hex'),'BROWSER',now()+interval '1 hour',?)")
        ->execute([$adminUser,hash('sha256',$browserToken),uuid()]);
    [$response,$body]=identityHttp('GET',$base.'/admin/access',[],[],['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===200 && $body['capabilities']===$adminAccess['capabilities'],'HTTP access hints match network admin grants');
    $pageRequest=new think\Request();
    $pageRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/drivers','PATH_INFO'=>'/admin/drivers','HTTP_HOST'=>'localhost:8000']);
    $pageRequest->withCookie(['zpx_delivery_session'=>$browserToken]);
    $pageApp=new think\App(dirname(__DIR__)); $pageApp->debug(false);
    $pageResponse=$pageApp->http->run($pageRequest);
    check($pageResponse->getCode()===200 && str_contains($pageResponse->getContent(),'Pending driver applications')
        && str_contains($pageResponse->getContent(),'HTTP approved applicant'),'admin driver page renders scoped pending records');
    $pageApp->http->end($pageResponse);
    $missingRequest=new think\Request();
    $missingRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin','PATH_INFO'=>'/admin','HTTP_HOST'=>'localhost:8000']);
    $missingApp=new think\App(dirname(__DIR__)); $missingApp->debug(false);
    $missingResponse=$missingApp->http->run($missingRequest);
    check($missingResponse->getCode()===303 && $missingResponse->getHeader('Location')==='/admin/login','anonymous admin page redirects to sign-in');
    $missingApp->http->end($missingResponse);
    $limitedToken=Secrets::token();
    $runtime->prepare('INSERT INTO auth_credentials(user_id,password_hash) VALUES (?,?)')->execute([$applicant,password_hash(Secrets::token(),PASSWORD_DEFAULT)]);
    $runtime->prepare("INSERT INTO auth_sessions(user_id,session_hash,client_kind,expires_at,family_id) VALUES (?,decode(?,'hex'),'BROWSER',now()+interval '1 hour',?)")
        ->execute([$applicant,hash('sha256',$limitedToken),uuid()]);
    [$response,$body]=identityHttp('GET',$base.'/admin/access',[],[],['zpx_delivery_session'=>$limitedToken]);
    check($response->getCode()===200 && $body['capabilities']===[] && $body['scopes'][0]['kind']==='LOCATION','location staff sees scope without admin capability');
    $deniedRequest=new think\Request();
    $deniedRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin','PATH_INFO'=>'/admin','HTTP_HOST'=>'localhost:8000']);
    $deniedRequest->withCookie(['zpx_delivery_session'=>$limitedToken]);
    $deniedApp=new think\App(dirname(__DIR__)); $deniedApp->debug(false);
    $deniedResponse=$deniedApp->http->run($deniedRequest);
    check($deniedResponse->getCode()===403 && str_contains($deniedResponse->getContent(),'Admin access unavailable')
        && str_contains($deniedResponse->getContent(),'Open operations portal')
        && str_contains($deniedResponse->getContent(),'action="/admin/logout"'),
        'location staff sees denial, assigned portal and sign-out');
    $deniedApp->http->end($deniedResponse);
    $unsafeRequest=new think\Request();
    $unsafeRequest->withServer(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/admin/action/driver-approve','PATH_INFO'=>'/admin/action/driver-approve','HTTP_HOST'=>'localhost:8000']);
    $unsafeRequest->withHeader(['origin'=>'http://localhost:8000','host'=>'localhost:8000']);
    $unsafeRequest->withCookie(['zpx_delivery_session'=>$browserToken]);
    $unsafeRequest->withPost(['id'=>$httpApprovedId,'idempotency_key'=>Secrets::uuid()]);
    $unsafeApp=new think\App(dirname(__DIR__)); $unsafeApp->debug(false);
    $unsafeResponse=$unsafeApp->http->run($unsafeRequest);
    check($unsafeResponse->getCode()===403 && str_contains($unsafeResponse->getContent(),'Reload the page'),'admin form action rejects missing CSRF');
    $unsafeApp->http->end($unsafeResponse);
    $headers=['origin'=>'http://localhost:5173','x-csrf-token'=>(new Secrets())->digest('csrf',$browserToken),'idempotency-key'=>Secrets::uuid()];
    [$response,$body]=identityHttp('POST',$base.'/admin/drivers/'.$foreignId.'/reject',['reason'=>'Denied by scope'],$headers,['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===404,'HTTP foreign rejection denied');
    [$response,$body]=identityHttp('POST',$base.'/admin/drivers/'.$foreignId.'/approve',[],$headers+['idempotency-key'=>Secrets::uuid()],['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===404,'HTTP foreign approval denied');
    [$response,$body]=identityHttp('POST',$base.'/admin/drivers/'.$httpApprovedId.'/approve',[],array_replace($headers,['idempotency-key'=>Secrets::uuid()]),['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===200 && $body['status']==='ACTIVE','HTTP driver approval succeeds');
    [$response,$body]=identityHttp('POST',$base.'/admin/drivers/'.$httpRejectedId.'/reject',['reason'=>'HTTP test rejection'],array_replace($headers,['idempotency-key'=>Secrets::uuid()]),['zpx_delivery_session'=>$browserToken]);
    check($response->getCode()===200 && $body['status']==='INACTIVE','HTTP driver rejection succeeds');
} finally { putenv($previousOrg === false ? 'ZPX_ORGANIZATION_ID' : 'ZPX_ORGANIZATION_ID='.$previousOrg); }
