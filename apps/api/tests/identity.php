<?php
declare(strict_types=1);
use Zpx\Identity\{Failure,Secrets,Service};

putenv('AUTH_ENCRYPTION_KEY='.bin2hex(random_bytes(32)));
putenv('AUTH_LOOKUP_KEY='.bin2hex(random_bytes(32)));
$identityOrg=insertId($runtime,"INSERT INTO organizations(name) VALUES ('Synthetic identity network')");
putenv('ZPX_ORGANIZATION_ID='.$identityOrg);
putenv('AUTH_ALLOWED_ORIGINS=http://localhost:5173');
$crypto=new Secrets(); $identity=new Service($runtime,$crypto);
function failsIdentity(callable $fn, int $status, string $message): void {
    try { $fn(); throw new LogicException('Expected identity rejection'); }
    catch (Failure $e) { check($e->status===$status,$message); }
}
$registration=['name'=>'Synthetic Alice','email'=>'alice@example.invalid','phone'=>'+12025550101','password'=>Secrets::token(),'address'=>['line1'=>'Synthetic fixture only','city'=>'Synthetic','region'=>'TX','postal_code'=>'00000','country_code'=>'US']];
failsIdentity(fn()=>$identity->register($registration+['role'=>'ADMIN']),422,'registration cannot self-assign staff role');
$first=$identity->register($registration); $alice=$first['user_id'];
check($first['status']==='VERIFICATION_REQUIRED','registration does not fabricate verified contacts');
failsIdentity(fn()=>$identity->requireVerified($alice),403,'unverified customer cannot satisfy shipping eligibility');
$saved=$runtime->query("SELECT value_ciphertext FROM user_contacts WHERE user_id=$alice AND kind='EMAIL'")->fetchColumn();
check(!str_contains($saved,'alice') && $crypto->decrypt($saved)==='alice@example.invalid','contact is encrypted at rest');
$tampered=base64_encode(str_repeat('x',64));
try { $crypto->decrypt($tampered); throw new LogicException('Tampered ciphertext accepted'); }
catch (RuntimeException $e) { check($e->getMessage()==='Invalid encrypted field','ciphertext modification rejected'); }
$second=$registration; $second['email']='bob@example.invalid'; $second['phone']='+12025550102'; $second['name']='Synthetic Bob';
$bob=$identity->register($second)['user_id'];
failsIdentity(fn()=>$identity->register($registration),409,'duplicate contacts cannot create another account');
failsIdentity(fn()=>$identity->login(['email'=>$registration['email'],'password'=>'incorrect','client_kind'=>'NATIVE']),401,'incorrect password denied');
failsIdentity(fn()=>$identity->login(['email'=>'unknown@example.invalid','password'=>'incorrect','client_kind'=>'NATIVE']),401,'unknown identity has the same credential error');
$native=$identity->login(['email'=>$registration['email'],'password'=>$registration['password'],'client_kind'=>'NATIVE']);
$session=$identity->authenticate($native['access_token'],'NATIVE');
check($session['user_id']===$alice && $identity->profile($session['user_id'])['name']==='Synthetic Alice','session resolves its own profile');
check($identity->profile($alice)['roles']===['CUSTOMER'],'public registration grants customer only');
failsIdentity(fn()=>$identity->requireRole($alice,'ADMIN'),403,'customer denied admin role');
failsIdentity(fn()=>$identity->authenticate($native['access_token'],'BROWSER'),401,'native token cannot masquerade as browser session');
$hash=$runtime->query("SELECT encode(session_hash,'hex') FROM auth_sessions WHERE id={$session['id']}")->fetchColumn();
check($hash===hash('sha256',$native['access_token']) && $hash!==$native['access_token'],'session token stored only as hash');

foreach (['EMAIL'=>$registration['email'],'PHONE'=>$registration['phone']] as $kind=>$target) {
    $challenge=$identity->challenge(['kind'=>$kind,'contact_value'=>$target,'purpose'=>'REGISTER']);
    $q=$runtime->prepare("SELECT o.payload FROM outbox_events o JOIN verification_challenges v ON v.id=o.aggregate_id WHERE o.event_type='identity.contact_verification' AND v.public_id=?"); $q->execute([$challenge['challenge_id']]);
    $payload=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $message=json_decode($crypto->decrypt($payload['encrypted_message']),true,512,JSON_THROW_ON_ERROR);
    check(!isset($challenge['code']) && !isset($payload['code']),'verification code absent from HTTP response and plaintext outbox');
    $identity->verify(['challenge_id'=>$challenge['challenge_id'],'code'=>$message['code']]);
    failsIdentity(fn()=>$identity->verify(['challenge_id'=>$challenge['challenge_id'],'code'=>$message['code']]),400,'verification is single-use');
}
check($identity->profile($alice)['email_verified'] && $identity->profile($alice)['phone_verified'],'both contact channels verified independently');
$identity->requireVerified($alice);
check(!$identity->profile($bob)['email_verified'],'verifying one user does not verify another');
$challenge=$identity->challenge(['kind'=>'EMAIL','contact_value'=>$second['email'],'purpose'=>'REGISTER']);
for ($i=0;$i<5;$i++) { failsIdentity(fn()=>$identity->verify(['challenge_id'=>$challenge['challenge_id'],'code'=>'000000']),400,'wrong verification code rejected'); }
$q=$runtime->prepare('SELECT attempts FROM verification_challenges WHERE public_id=?'); $q->execute([$challenge['challenge_id']]);
check((int)$q->fetchColumn()===5,'failed verification attempts persist despite error response');
failsIdentity(fn()=>$identity->verify(['challenge_id'=>$challenge['challenge_id'],'code'=>'000000']),400,'exhausted challenge stays locked');
$unknown=$identity->challenge(['kind'=>'EMAIL','contact_value'=>'nobody@example.invalid','purpose'=>'REGISTER']);
check(array_keys($unknown)===array_keys($challenge) && $unknown['delivery_status']===$challenge['delivery_status'],'unknown contact receives neutral challenge response');

$rotated=$identity->refresh(['refresh_token'=>$native['refresh_token']]);
failsIdentity(fn()=>$identity->authenticate($native['access_token'],'NATIVE'),401,'refresh revokes previous access token');
check($identity->authenticate($rotated['access_token'],'NATIVE')['user_id']===$alice,'rotated access token works');
failsIdentity(fn()=>$identity->refresh(['refresh_token'=>$native['refresh_token']]),401,'replayed refresh rejected');
failsIdentity(fn()=>$identity->authenticate($rotated['access_token'],'NATIVE'),401,'refresh replay revokes the entire session family');

$race=$identity->login(['email'=>$second['email'],'password'=>$second['password'],'client_kind'=>'NATIVE']);
$raceSession=$identity->authenticate($race['access_token'],'NATIVE');
$runtime->beginTransaction();
$q=$runtime->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))'); $q->execute([$raceSession['family_id']]);
$children=[]; $markers=[];
try {
    for ($i=0;$i<2;$i++) {
        $marker='refresh-race-'.bin2hex(random_bytes(6)); $markers[]=$marker;
        $child=proc_open([PHP_BINARY,__DIR__.'/integration.php','--refresh-race',$marker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$childPipes);
        if (!is_resource($child)) { throw new RuntimeException('Cannot start refresh contender'); }
        fwrite($childPipes[0],$race['refresh_token']."\n"); fclose($childPipes[0]); unset($childPipes[0]);
        stream_set_timeout($childPipes[1],10);
        check(trim((string)fgets($childPipes[1]))==='READY','independent refresh contender connected');
        $children[]=[$child,$childPipes];
    }
    $waiting=false;
    $q=$runtime->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name IN (?,?) AND wait_event_type='Lock'");
    for ($i=0;$i<100;$i++) {
        $runtime->query('SELECT pg_stat_clear_snapshot()'); $q->execute($markers);
        if ((int)$q->fetchColumn()===2) { $waiting=true; break; } usleep(10000);
    }
    check($waiting,'concurrent refresh requests serialize on the same family');
    $runtime->commit();
    $statuses=[];
    foreach ($children as [$child,$childPipes]) {
        $statuses[]=trim(stream_get_contents($childPipes[1]));
        foreach ($childPipes as $pipe) { fclose($pipe); }
        check(proc_close($child)===0,'refresh contender completed');
    }
    $children=[]; sort($statuses);
    check($statuses===['200','401'],'concurrent refresh has one winner and rejects replay');
    $q=$runtime->prepare('SELECT count(*) FROM auth_sessions WHERE family_id=? AND revoked_at IS NULL'); $q->execute([$raceSession['family_id']]);
    check((int)$q->fetchColumn()===0,'concurrent refresh replay revokes all descendants');
} finally {
    if ($runtime->inTransaction()) { $runtime->rollBack(); }
    foreach ($children as [$child,$childPipes]) {
        if (is_resource($child)) { proc_terminate($child); foreach ($childPipes as $pipe) { if (is_resource($pipe)) fclose($pipe); } proc_close($child); }
    }
}

$browser=$identity->login(['email'=>$registration['email'],'password'=>$registration['password'],'client_kind'=>'BROWSER']);
check(!isset($browser['access_token']) && !isset($browser['refresh_token']) && isset($browser['csrf_token']),'browser login does not return native bearer tokens');
failsIdentity(fn()=>$identity->csrf($browser['_cookie'],'wrong'),403,'browser CSRF forgery rejected');
$identity->csrf($browser['_cookie'],$browser['csrf_token']);
$browserSession=$identity->authenticate($browser['_cookie'],'BROWSER');
$logoutKey=Secrets::uuid();
$logout=$identity->logout($browserSession,$logoutKey);
$repeatLogout=$identity->logout($browserSession,$logoutKey);
check($repeatLogout['operation_id']===$logout['operation_id'] && $repeatLogout['status']===$logout['status'],'already-authorized duplicate logout returns the same operation');
check((int)$runtime->query("SELECT count(*) FROM audit_events WHERE action='LOGOUT' AND entity_id='{$browserSession['id']}'")->fetchColumn()===1,'duplicate logout emits one audit event');
failsIdentity(fn()=>$identity->authenticate($browser['_cookie'],'BROWSER'),401,'logout revokes session');

$loc=insertId($runtime,"INSERT INTO locations(organization_id,code,name,kind,address_text,status) VALUES (?,?,'Synthetic hub','HUB','Not real','ACTIVE')",[$identityOrg,uuid()]);
$other=insertId($runtime,"INSERT INTO locations(organization_id,code,name,kind,address_text,status) VALUES (?,?,'Other hub','HUB','Not real','ACTIVE')",[$identityOrg,uuid()]);
$runtime->exec("INSERT INTO roles(code) VALUES ('HUB_STAFF') ON CONFLICT DO NOTHING");
$role=$runtime->query("SELECT id FROM roles WHERE code='HUB_STAFF'")->fetchColumn();
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,location_id,granted_by) VALUES ($alice,$role,$identityOrg,$loc,$alice)");
$identity->requireRole($alice,'HUB_STAFF',$loc);
failsIdentity(fn()=>$identity->requireRole($alice,'HUB_STAFF',$other),403,'hub assignment does not grant another site');
failsIdentity(fn()=>$identity->requireRole($alice,'HUB_STAFF'),403,'site grant is not organization-wide');
$runtime->exec("UPDATE scoped_role_grants SET expires_at=now()-interval '1 second' WHERE user_id=$alice AND role_id=$role");
failsIdentity(fn()=>$identity->requireRole($alice,'HUB_STAFF',$loc),403,'expired staff grant denied');
$outsideOrg=insertId($runtime,"INSERT INTO organizations(name) VALUES ('Other synthetic network')");
$runtime->beginTransaction();
try { rejected($runtime,"INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES ($alice,$role,$outsideOrg,$alice)",'23503'); }
finally { $runtime->rollBack(); }
putenv('ZPX_ORGANIZATION_ID='.$outsideOrg);
failsIdentity(fn()=>$identity->profile($alice),403,'profile access cannot cross organization');
putenv('ZPX_ORGANIZATION_ID='.$identityOrg);
for ($i=0;$i<3;$i++) { $identity->limit('test-limit',3); }
failsIdentity(fn()=>$identity->limit('test-limit',3),429,'rate limits persist in PostgreSQL');

function identityHttp(string $method, string $path, array $input=[], array $headers=[], array $cookies=[]): array {
    $app=new think\App(dirname(__DIR__)); $app->debug(false);
    $request=new think\Request();
    $request->withServer(['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$path,'PATH_INFO'=>$path,'REMOTE_ADDR'=>'127.0.0.1']);
    $request->withHeader($headers+['content-type'=>'application/json','origin'=>'http://localhost:5173']);
    $request->withCookie($cookies);
    $request->withInput(json_encode($input ?: new stdClass(),JSON_THROW_ON_ERROR));
    $response=$app->http->run($request);
    $body=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
    $app->http->end($response);
    return [$response,$body];
}
$base='/api/delivery/v1';
[$response,$body]=identityHttp('POST',$base.'/auth/login',['email'=>$second['email'],'password'=>$second['password'],'client_kind'=>'BROWSER']);
check($response->getCode()===200 && isset($body['csrf_token']) && !isset($body['_cookie']) && !isset($body['access_token']),'HTTP browser login returns only safe session fields');
$cookieHeader=$response->getHeader('Set-Cookie');
check(str_contains($cookieHeader,'HttpOnly') && str_contains($cookieHeader,'SameSite=Strict') && str_contains($cookieHeader,'Secure'),'browser cookie security attributes');
preg_match('/zpx_delivery_session=([a-f0-9]{64})/',$cookieHeader,$matches);
$cookies=['zpx_delivery_session'=>$matches[1]];
$csrf=$body['csrf_token'];
[$response,$body]=identityHttp('GET',$base.'/me',[],['x-user-id'=>$alice],$cookies);
check($response->getCode()===200 && $body['user_id']===$bob,'profile ignores forged user selector and follows authenticated session');
[$response,$body]=identityHttp('POST',$base.'/auth/logout',[],['idempotency-key'=>Secrets::uuid()],$cookies);
check($response->getCode()===403 && $body['code']==='CSRF_REJECTED' && isset($body['correlation_id']),'HTTP cookie mutation requires CSRF');
[$response,$body]=identityHttp('POST',$base.'/auth/logout',[],['idempotency-key'=>Secrets::uuid(),'x-csrf-token'=>$csrf,'origin'=>'https://attacker.invalid'],$cookies);
check($response->getCode()===403 && $body['code']==='ORIGIN_REJECTED','cross-origin authenticated mutation denied');
[$response,$body]=identityHttp('POST',$base.'/auth/logout',[],['idempotency-key'=>Secrets::uuid(),'x-csrf-token'=>$csrf],$cookies);
check($response->getCode()===200 && str_contains($response->getHeader('Set-Cookie'),'Max-Age=0'),'HTTP logout clears cookie');
[$response,$body]=identityHttp('GET',$base.'/me',[],[],$cookies);
check($response->getCode()===401,'logged-out browser cannot retrieve profile');
[$response,$body]=identityHttp('POST',$base.'/auth/register',$registration,['content-type'=>'text/plain']);
check($response->getCode()===415,'non-JSON authentication requests rejected');
$last=$identity->login(['email'=>$second['email'],'password'=>$second['password'],'client_kind'=>'NATIVE']);
$runtime->exec("UPDATE users SET status='DISABLED' WHERE id=$bob");
failsIdentity(fn()=>$identity->authenticate($last['access_token'],'NATIVE'),401,'disabled account loses existing session access');
echo "Identity integration passed using synthetic users only.\n";
