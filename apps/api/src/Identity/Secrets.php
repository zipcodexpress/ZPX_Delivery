<?php
declare(strict_types=1);
namespace Zpx\Identity;

final class Secrets
{
    private string $encryption;
    private string $lookup;
    public function __construct()
    {
        $this->encryption = $this->key('AUTH_ENCRYPTION_KEY');
        $this->lookup = $this->key('AUTH_LOOKUP_KEY');
    }
    private function key(string $name): string
    {
        $value = getenv($name) ?: '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) { throw new Failure(503, 'AUTH_NOT_CONFIGURED', 'Identity service is not configured.'); }
        return hex2bin($value);
    }
    public function encrypt(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->encryption));
    }
    public function decrypt(string $value): string
    {
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) < 40) { throw new \RuntimeException('Invalid encrypted field'); }
        $plain = sodium_crypto_secretbox_open(substr($bytes,24), substr($bytes,0,24), $this->encryption);
        if ($plain === false) { throw new \RuntimeException('Invalid encrypted field'); }
        return $plain;
    }
    public function digest(string $domain, string $value): string
    { return hash_hmac('sha256', $domain . "\0" . $value, $this->lookup); }
    public static function token(): string { return bin2hex(random_bytes(32)); }
    public static function uuid(): string
    {
        $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
