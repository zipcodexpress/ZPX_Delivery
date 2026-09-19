<?php
declare(strict_types=1);
namespace Zpx\Identity;

use PDO;
use PDOException;
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;

final class Service
{
    public function __construct(private PDO $db, private Secrets $secrets) {}

    private function query(string $sql, array $args = []): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function atomic(callable $fn): mixed
    {
        $result=(new Transaction($this->db))->run($fn);
        // Rejections that update attempt counters/revoke a replayed family must commit first.
        if ($result instanceof Failure) { throw $result; }
        return $result;
    }
    private function organization(): string
    {
        $id=getenv('ZPX_ORGANIZATION_ID') ?: '';
        if (!ctype_digit($id) || !$this->query('SELECT id FROM organizations WHERE id=?',[$id])->fetchColumn()) {
            throw new Failure(503,'AUTH_NOT_CONFIGURED','Identity service is not configured.');
        }
        return $id;
    }
    public function limit(string $scope, int $maximum=10): void
    {
        $hits=$this->query("INSERT INTO auth_rate_limits(scope_hash,bucket,hits) VALUES (decode(?,'hex'),floor(extract(epoch from clock_timestamp())/600)::bigint,1) ON CONFLICT(scope_hash,bucket) DO UPDATE SET hits=auth_rate_limits.hits+1 RETURNING hits",[$this->secrets->digest('rate',$scope)])->fetchColumn();
        if ((int)$hits>$maximum) { throw new Failure(429,'RATE_LIMITED','Too many attempts. Try again later.'); }
    }
    public function register(array $input): array
    {
        Input::fields($input,['name','email','phone','password','address']);
        $name=trim(Input::text($input['name'],1,160));
        if ($name==='') { throw new Failure(422,'INVALID_INPUT','Name is required.'); }
        $email=Input::contact('EMAIL',$input['email']); $phone=Input::contact('PHONE',$input['phone']);
        $password=Input::text($input['password'],12,72); $address=Input::address($input['address']);
        $org=$this->organization();
        $this->limit('register:'.$email,5);
        try {
            return $this->atomic(function () use ($org,$name,$email,$phone,$password,$address) {
                $user=(string)$this->query("INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?,?,'ACTIVE') RETURNING id",[$org,Secrets::uuid(),$name])->fetchColumn();
                $this->query('INSERT INTO auth_credentials(user_id,password_hash,password_changed_at) VALUES (?,?,now())',[$user,password_hash($password,PASSWORD_BCRYPT,['cost'=>12])]);
                foreach (['EMAIL'=>$email,'PHONE'=>$phone] as $kind=>$value) {
                    $this->query("INSERT INTO user_contacts(user_id,kind,value_ciphertext,lookup_hmac,key_version) VALUES (?,?,?,decode(?,'hex'),1)",[$user,$kind,$this->secrets->encrypt($value),$this->secrets->digest('contact:'.$kind,$value)]);
                }
                $this->query("INSERT INTO user_addresses(user_id,kind,address_ciphertext,country_code,key_version) VALUES (?,'PROFILE',?,?,1)",[$user,$this->secrets->encrypt(json_encode($address,JSON_THROW_ON_ERROR)),$address['country_code']]);
                $this->query("INSERT INTO roles(code) VALUES ('CUSTOMER') ON CONFLICT DO NOTHING");
                $role=$this->query("SELECT id FROM roles WHERE code='CUSTOMER'")->fetchColumn();
                $this->query('INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES (?,?,?,?)',[$user,$role,$org,$user]);
                $this->audit($user,'CUSTOMER_REGISTERED',$user);
                return ['user_id'=>$user,'status'=>'VERIFICATION_REQUIRED'];
            });
        } catch (PDOException $e) {
            if ($e->getCode()==='23505') { throw new Failure(409,'REGISTRATION_UNAVAILABLE','Registration could not be completed. Sign in or use account recovery.'); }
            throw $e;
        }
    }
    public function login(array $input): array
    {
        Input::fields($input,['email','password','client_kind']);
        $email=Input::contact('EMAIL',$input['email']); $password=Input::text($input['password'],1,72);
        if (!in_array($input['client_kind'],['BROWSER','NATIVE'],true)) { throw new Failure(422,'INVALID_INPUT','Invalid client kind.'); }
        $this->limit('login:'.$email);
        $user=$this->query("SELECT u.id,c.password_hash,c.disabled_at,u.status FROM users u JOIN auth_credentials c ON c.user_id=u.id JOIN user_contacts contact ON contact.user_id=u.id AND contact.kind='EMAIL' WHERE u.organization_id=? AND contact.lookup_hmac=decode(?,'hex')",[$this->organization(),$this->secrets->digest('contact:EMAIL',$email)])->fetch(PDO::FETCH_ASSOC);
        // Valid bcrypt dummy hash, never associated with an account.
        $dummy='$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid=password_verify($password,$user['password_hash'] ?? $dummy);
        if (!$valid || !$user || $user['status']!=='ACTIVE' || $user['disabled_at']!==null) { throw new Failure(401,'INVALID_CREDENTIALS','Email or password is incorrect.'); }
        return $this->atomic(function () use ($user,$input) {
            // A concurrent disable/revoke must serialize with creating a new session.
            $active=$this->query('SELECT u.status,c.disabled_at,c.password_hash FROM users u JOIN auth_credentials c ON c.user_id=u.id WHERE u.id=? FOR UPDATE OF u,c',[$user['id']])->fetch(PDO::FETCH_ASSOC);
            if ($active['status']!=='ACTIVE' || $active['disabled_at']!==null || !hash_equals($active['password_hash'],$user['password_hash'])) { return new Failure(401,'INVALID_CREDENTIALS','Email or password is incorrect.'); }
            $this->audit($user['id'],'LOGIN',$user['id']);
            return $this->session($user['id'],$input['client_kind'],Secrets::uuid());
        });
    }
    private function session(string $user, string $kind, string $family, ?string $refreshExpiry=null): array
    {
        $access=Secrets::token(); $refresh=$kind==='NATIVE'?Secrets::token():null;
        $seconds=$kind==='BROWSER'?28800:900;
        $refreshExpiry=$kind==='NATIVE'?($refreshExpiry ?? gmdate('c',time()+604800)):null;
        $row=$this->query("INSERT INTO auth_sessions(user_id,session_hash,client_kind,refresh_hash,expires_at,refresh_expires_at,family_id) VALUES (?,decode(?,'hex'),?,decode(?,'hex'),now()+? * interval '1 second',?,?) RETURNING expires_at",[$user,hash('sha256',$access),$kind,$refresh===null?null:hash('sha256',$refresh),$seconds,$refreshExpiry,$family])->fetch(PDO::FETCH_ASSOC);
        $profile=$this->profile($user);
        $result=['user_id'=>$user,'status'=>$profile['email_verified']&&$profile['phone_verified']?'AUTHENTICATED':'VERIFICATION_REQUIRED','expires_at'=>gmdate('c',strtotime($row['expires_at']))];
        if ($kind==='NATIVE') { return $result+['access_token'=>$access,'refresh_token'=>$refresh]; }
        return $result+['csrf_token'=>$this->secrets->digest('csrf',$access),'_cookie'=>$access];
    }
    public function authenticate(string $token, string $kind): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) { throw new Failure(401,'AUTH_REQUIRED','Sign in to continue.'); }
        $row=$this->query("SELECT s.id,s.user_id,s.family_id FROM auth_sessions s JOIN users u ON u.id=s.user_id JOIN auth_credentials c ON c.user_id=u.id WHERE s.session_hash=decode(?,'hex') AND s.client_kind=? AND s.revoked_at IS NULL AND s.expires_at>now() AND u.status='ACTIVE' AND c.disabled_at IS NULL AND u.organization_id=?",[hash('sha256',$token),$kind,$this->organization()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(401,'AUTH_REQUIRED','Sign in to continue.'); }
        return $row;
    }
    public function csrf(string $token, string $provided): void
    {
        if (!hash_equals($this->secrets->digest('csrf',$token),$provided)) { throw new Failure(403,'CSRF_REJECTED','Reload the page and try again.'); }
    }
    public function profile(string $user): array
    {
        $row=$this->query('SELECT display_name FROM users WHERE id=? AND organization_id=?',[$user,$this->organization()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(403,'ACCESS_DENIED','Access denied.'); }
        $contacts=$this->query('SELECT kind FROM user_contacts WHERE user_id=? AND verified_at IS NOT NULL',[$user])->fetchAll(PDO::FETCH_COLUMN);
        $roles=$this->query('SELECT DISTINCT r.code FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id WHERE g.user_id=? AND g.organization_id=? AND (g.expires_at IS NULL OR g.expires_at>now()) ORDER BY r.code',[$user,$this->organization()])->fetchAll(PDO::FETCH_COLUMN);
        $addresses=$this->query('SELECT address_ciphertext FROM user_addresses WHERE user_id=? ORDER BY id',[$user])->fetchAll(PDO::FETCH_COLUMN);
        return ['user_id'=>$user,'name'=>$row['display_name'],'email_verified'=>in_array('EMAIL',$contacts,true),'phone_verified'=>in_array('PHONE',$contacts,true),'roles'=>$roles,'addresses'=>array_map(fn($v)=>json_decode($this->secrets->decrypt($v),true,512,JSON_THROW_ON_ERROR),$addresses)];
    }
    public function requireRole(string $user, string $role, ?string $location=null): void
    {
        $grant=$this->query('SELECT g.id FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id JOIN users u ON u.id=g.user_id WHERE g.user_id=? AND g.organization_id=? AND r.code=? AND u.status=\'ACTIVE\' AND (g.expires_at IS NULL OR g.expires_at>now()) AND (g.location_id IS NULL OR g.location_id=CAST(? AS bigint))',[$user,$this->organization(),$role,$location])->fetchColumn();
        if (!$grant) { throw new Failure(403,'ACCESS_DENIED','Access denied.'); }
    }
    public function requireVerified(string $user): void
    {
        $profile=$this->profile($user);
        if (!$profile['email_verified'] || !$profile['phone_verified']) { throw new Failure(403,'CONTACT_VERIFICATION_REQUIRED','Verify email and phone before shipping.'); }
    }
    public function refresh(array $input): array
    {
        Input::fields($input,['refresh_token']); $token=Input::text($input['refresh_token'],64,64);
        return $this->atomic(function () use ($token) {
            $family=$this->query("SELECT family_id FROM auth_sessions WHERE refresh_hash=decode(?,'hex')",[hash('sha256',$token)])->fetchColumn();
            if (!$family) { return new Failure(401,'INVALID_REFRESH','Sign in again.'); }
            $this->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$family]);
            $row=$this->query("SELECT s.*,u.status,c.disabled_at FROM auth_sessions s JOIN users u ON u.id=s.user_id JOIN auth_credentials c ON c.user_id=u.id WHERE s.refresh_hash=decode(?,'hex') AND s.client_kind='NATIVE' AND u.organization_id=? FOR UPDATE OF s,u,c",[hash('sha256',$token),$this->organization()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { return new Failure(401,'INVALID_REFRESH','Sign in again.'); }
            if ($row['revoked_at']!==null || strtotime($row['refresh_expires_at'])<=time() || $row['status']!=='ACTIVE' || $row['disabled_at']!==null) {
                $this->query('UPDATE auth_sessions SET revoked_at=COALESCE(revoked_at,now()) WHERE family_id=?',[$row['family_id']]);
                return new Failure(401,'INVALID_REFRESH','Sign in again.');
            }
            $this->query('UPDATE auth_sessions SET revoked_at=now() WHERE id=?',[$row['id']]);
            return $this->session($row['user_id'],'NATIVE',$row['family_id'],$row['refresh_expires_at']);
        });
    }
    public function logout(array $session, string $requestKey): array
    {
        Input::text($requestKey,16,100);
        return $this->atomic(function () use ($session,$requestKey) {
            $this->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$session['family_id']]);
            $scope='logout:'.$session['id'];
            $saved=$this->query('SELECT response_body FROM idempotency_records WHERE scope=? AND request_key=?',[$scope,$requestKey])->fetchColumn();
            if ($saved!==false) { return json_decode($saved,true,512,JSON_THROW_ON_ERROR); }
            $active=$this->query('SELECT id FROM auth_sessions WHERE id=? AND user_id=? AND revoked_at IS NULL',[$session['id'],$session['user_id']])->fetchColumn();
            if (!$active) { return new Failure(401,'AUTH_REQUIRED','Sign in to continue.'); }
            $this->query('UPDATE auth_sessions SET revoked_at=COALESCE(revoked_at,now()) WHERE family_id=?',[$session['family_id']]);
            $this->audit($session['user_id'],'LOGOUT',$session['id']);
            $result=['operation_id'=>Secrets::uuid(),'status'=>'COMPLETED'];
            $this->query("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '1 day')",[$scope,$requestKey,hash('sha256','{}'),json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
    public function challenge(array $input): array
    {
        Input::fields($input,['kind','contact_value','purpose']);
        if (!in_array($input['kind'],['EMAIL','PHONE'],true)) { throw new Failure(422,'INVALID_INPUT','Invalid contact kind.'); }
        if ($input['purpose']!=='REGISTER') { throw new Failure(422,'PURPOSE_UNAVAILABLE','This verification purpose is not available yet.'); }
        $value=Input::contact($input['kind'],$input['contact_value']);
        $this->limit('challenge:'.$input['kind'].':'.$value,5);
        // No real provider is silently substituted. Development mail is encrypted in the outbox.
        if (!in_array(getenv('APP_ENV'),['development','test'],true)) { throw new Failure(503,'PROVIDER_NOT_CONFIGURED','Verification delivery is not configured.'); }
        return $this->atomic(function () use ($input,$value) {
            $target=$this->secrets->digest('contact:'.$input['kind'],$value);
            $user=$this->query("SELECT u.id FROM users u JOIN user_contacts c ON c.user_id=u.id WHERE c.lookup_hmac=decode(?,'hex') AND c.kind=? AND u.organization_id=? AND u.status='ACTIVE'",[$target,$input['kind'],$this->organization()])->fetchColumn();
            $code=(string)random_int(100000,999999); $id=Secrets::uuid();
            $row=$this->query("INSERT INTO verification_challenges(user_id,purpose,target_hmac,secret_hash,expires_at,public_id,contact_kind) VALUES (?,'REGISTER',decode(?,'hex'),decode(?,'hex'),now()+interval '10 minutes',?,?) RETURNING id,expires_at",[$user?:null,$target,$this->secrets->digest('challenge:'.$id,$code),$id,$input['kind']])->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $message=$this->secrets->encrypt(json_encode(['challenge_id'=>$id,'kind'=>$input['kind'],'to'=>$value,'code'=>$code],JSON_THROW_ON_ERROR));
                (new Outbox($this->db))->append(Secrets::uuid(),'verification_challenge',$row['id'],'identity.contact_verification',['encrypted_message'=>$message,'delivery'=>'LOCAL_ONLY']);
            }
            return ['challenge_id'=>$id,'expires_at'=>gmdate('c',strtotime($row['expires_at'])),'delivery_status'=>'QUEUED'];
        });
    }
    public function verify(array $input): array
    {
        Input::fields($input,['challenge_id','code']);
        $id=Input::text($input['challenge_id'],36,36); $code=Input::text($input['code'],6,6);
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id) || !preg_match('/^[0-9]{6}$/D',$code)) { throw new Failure(422,'INVALID_INPUT','Invalid challenge or code format.'); }
        return $this->atomic(function () use ($id,$code) {
            $row=$this->query("SELECT *,encode(secret_hash,'hex') AS secret FROM verification_challenges WHERE public_id=? FOR UPDATE",[$id])->fetch(PDO::FETCH_ASSOC);
            $failure=new Failure(400,'INVALID_VERIFICATION','Verification is invalid or expired.');
            if (!$row || $row['consumed_at']!==null || $row['purpose']!=='REGISTER' || strtotime($row['expires_at'])<=time() || (int)$row['attempts']>=5) { return $failure; }
            $this->query('UPDATE verification_challenges SET attempts=attempts+1 WHERE id=?',[$row['id']]);
            if (!$row['user_id'] || !hash_equals($row['secret'],$this->secrets->digest('challenge:'.$id,$code))) { return $failure; }
            if (!$this->query("SELECT id FROM users WHERE id=? AND organization_id=? AND status='ACTIVE'",[$row['user_id'],$this->organization()])->fetchColumn()) { return $failure; }
            $changed=$this->query('UPDATE user_contacts SET verified_at=COALESCE(verified_at,now()) WHERE user_id=? AND kind=? AND lookup_hmac=(SELECT target_hmac FROM verification_challenges WHERE id=?)',[$row['user_id'],$row['contact_kind'],$row['id']]);
            if ($changed->rowCount()!==1) { return $failure; }
            $this->query('UPDATE verification_challenges SET consumed_at=now() WHERE id=?',[$row['id']]);
            $this->audit($row['user_id'],'CONTACT_VERIFIED',$row['id']);
            return ['operation_id'=>Secrets::uuid(),'status'=>'COMPLETED'];
        });
    }
    private function audit(string $user, string $action, string $resource): void
    { $this->query("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,?,'identity',?)",[$user,$action,$resource]); }
}
