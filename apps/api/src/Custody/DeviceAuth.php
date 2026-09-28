<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use PDOException;
use think\Request;
use Zpx\Identity\Failure;

/** Verify an enrolled terminal signature and consume its nonce inside the caller's transaction. */
final class DeviceAuth
{
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }

    public function authenticate(Request $request,string $method,string $path): array
    {
        if ($request->method(true)!==$method) { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
        $key=$request->header('x-device-key-id','');
        $time=$request->header('x-device-timestamp','');
        $nonce=$request->header('x-device-nonce','');
        $signature=$request->header('x-device-signature','');
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9._-]{1,80}$/D',$key)
            || !is_string($time) || !preg_match('/^[0-9]{10}$/D',$time) || abs(time()-(int)$time)>60
            || !is_string($nonce) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8a-f][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$nonce)
            || !is_string($signature)) { throw new Failure(401,'DEVICE_AUTH_REQUIRED','Valid signed device headers are required.'); }
        $sig=base64_decode($signature,true);
        if ($sig===false || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES) { throw new Failure(401,'DEVICE_AUTH_REQUIRED','Valid device signature required.'); }
        $credential=$this->q("SELECT d.id,d.locker_id,dc.public_key FROM device_credentials dc
            JOIN locker_devices d ON d.id=dc.device_id JOIN lockers k ON k.id=d.locker_id
            JOIN locations l ON l.id=k.location_id
            WHERE dc.key_id=? AND dc.revoked_at IS NULL AND dc.valid_from<=now()
              AND (dc.expires_at IS NULL OR dc.expires_at>now()) AND d.status='ACTIVE'
              AND l.organization_id=? AND k.capabilities->>'physical_commands_enabled'='true'
              AND k.capabilities->>'synthetic'='false'",[$key,(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0')])->fetch(PDO::FETCH_ASSOC);
        if (!$credential) { throw new Failure(401,'DEVICE_AUTH_REQUIRED','Device credential unavailable.'); }
        $public=base64_decode((string)$credential['public_key'],true);
        $canonical=$method."\n".$path."\n".$time."\n".$nonce."\n".hash('sha256',$request->getInput());
        if ($public===false || strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($sig,$canonical,$public)) {
            throw new Failure(401,'DEVICE_AUTH_REQUIRED','Device signature rejected.');
        }
        try { $this->q('INSERT INTO device_request_nonces(device_id,nonce) VALUES (?,?::uuid)',[$credential['id'],$nonce]); }
        catch (PDOException $e) {
            if ($e->getCode()==='23505') { throw new Failure(409,'DEVICE_REQUEST_REPLAY','Signed device request already used.'); }
            throw $e;
        }
        $this->q("DELETE FROM device_request_nonces WHERE observed_at<now()-interval '10 minutes'");
        return $credential;
    }
}
