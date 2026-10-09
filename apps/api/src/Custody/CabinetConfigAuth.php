<?php
declare(strict_types=1);
namespace Zpx\Custody;
use PDO;
use Zpx\Identity\{Failure,Input,Secrets};
use Zpx\Infrastructure\Database\Transaction;

/** Exact Terminal452 config signature and token envelope, scoped to a bound cabinet. */
final class CabinetConfigAuth {
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): \PDOStatement {$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public static function signature(string $key,string $secret,string $timestamp,string $cabinet): string {
        return md5('apiKey='.$key.'&apiSecret='.$secret.'&timestamp='.$timestamp.'&cabinetId='.$cabinet);
    }
    public function issue(array $input): array {
        Input::fields($input,['apiKey','kts','cabinetId','sign']);
        $cabinet=\Zpx\Shipping\Service::id($input['cabinetId']);$key=Input::text($input['apiKey'],1,64);
        $time=Input::text((string)$input['kts'],1,12);$sign=Input::text($input['sign'],32,32);
        if(!ctype_digit($time)||abs(time()-(int)$time)>90)throw new Failure(401,'CABINET_AUTH_REQUIRED','Cabinet timestamp expired.');
        return (new Transaction($this->db))->run(function()use($cabinet,$key,$time,$sign){
            $r=$this->q("SELECT c.*,l.id AS locker_id FROM cabinet c JOIN lockers l ON l.id=c.bound_locker_id JOIN locations loc ON loc.id=l.location_id
                WHERE c.cabinet_id=? AND c.organization_id=? AND c.status='BOUND' AND loc.status='ACTIVE' AND loc.site_mode='DELIVERY_ONLY'
                AND l.capabilities->>'physical_commands_enabled'='true' AND l.capabilities->>'synthetic'='false'
                AND NOT EXISTS(SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=loc.id) FOR UPDATE OF c",[$cabinet,getenv('ZPX_ORGANIZATION_ID') ?: '0'])->fetch(PDO::FETCH_ASSOC);
            if(!$r||!$r['api_key']||!$r['api_secret']||!hash_equals($r['api_key'],$key)||!hash_equals(self::signature($key,$r['api_secret'],$time,$cabinet),strtolower($sign)))throw new Failure(401,'CABINET_AUTH_REQUIRED','Cabinet configuration authentication failed.');
            $external='terminal452-cabinet-'.$cabinet;
            $d=$this->q("SELECT id,locker_id,status FROM locker_devices WHERE external_device_id=?",[$external])->fetch(PDO::FETCH_ASSOC);
            if($d&&($d['status']!=='ACTIVE'||$d['locker_id']!==$r['locker_id']))throw new Failure(401,'CABINET_AUTH_REQUIRED','Cabinet terminal is inactive.');
            $device=$d['id'] ?? $this->q("INSERT INTO locker_devices(locker_id,external_device_id,status) VALUES (?,?,'ACTIVE') RETURNING id",[$r['locker_id'],$external])->fetchColumn();
            $token=Secrets::token();$fingerprint=hash('sha256',$r['api_key']."\0".$r['api_secret']);
            $this->q("INSERT INTO cabinet_access_tokens(cabinet_id,device_id,token_hash,credentials_hash,expires_at) VALUES (?,?,decode(?,'hex'),decode(?,'hex'),now()+interval '24 hours')",[$cabinet,$device,hash('sha256',$token),$fingerprint]);
            return ['ret'=>0,'msg'=>'success','data'=>['accessToken'=>$token,'expire'=>86400,'cabinetId'=>$cabinet,'address'=>$r['address'] ?: '','zipcode'=>$r['zipcode'] ?: '','serviceType'=>$r['service_type'] ?: 'zippora']];
        });
    }
    public function configured(string $device): bool {
        return (bool)$this->q("SELECT 1 FROM cabinet c JOIN locker_devices d ON d.locker_id=c.bound_locker_id
            WHERE d.id=? AND d.status='ACTIVE' AND c.status='BOUND' AND c.organization_id=?
            AND d.external_device_id='terminal452-cabinet-'||c.cabinet_id::text
            AND coalesce(c.api_key,'')<>'' AND coalesce(c.api_secret,'')<>''",[$device,getenv('ZPX_ORGANIZATION_ID') ?: '0'])->fetchColumn();
    }
    public function adminCards(string $token): array {
        $device=$this->authenticate($token);
        $rows=$this->q("SELECT a.card_id,a.rfid,a.cabinet_id,a.zp_admin_id,a.zp_admin_name,a.zp_admin_role
            FROM cabinet_admin_card a JOIN cabinet c ON c.cabinet_id=a.cabinet_id
            WHERE c.cabinet_id=? AND c.organization_id=? AND c.status='BOUND' AND a.status=1
            AND lower(replace(a.zp_admin_role,' ','')) IN ('superadmin','admin','apartmentmanager') ORDER BY a.card_id",
            [$device['cabinet_id'],getenv('ZPX_ORGANIZATION_ID') ?: '0'])->fetchAll(PDO::FETCH_ASSOC);
        $cards=array_map(static fn(array $r): array => ['cardId'=>(string)$r['card_id'],'rfid'=>$r['rfid'],
            'cabinetId'=>(string)$r['cabinet_id'],'zpAdminId'=>$r['zp_admin_id'],'zpAdminName'=>$r['zp_admin_name'],'zpAdminRole'=>$r['zp_admin_role']],$rows);
        return ['ret'=>0,'msg'=>'success','data'=>$cards];
    }
    public function authenticate(string $token): array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new Failure(401,'CABINET_AUTH_REQUIRED','Valid cabinet access token required.');
        $r=$this->q("SELECT d.id,d.locker_id,c.cabinet_id,c.api_key,c.api_secret,encode(t.credentials_hash,'hex') AS fingerprint FROM cabinet_access_tokens t JOIN cabinet c ON c.cabinet_id=t.cabinet_id AND c.status='BOUND'
            JOIN locker_devices d ON d.id=t.device_id AND d.locker_id=c.bound_locker_id AND d.status='ACTIVE' JOIN lockers l ON l.id=d.locker_id JOIN locations loc ON loc.id=l.location_id
            WHERE t.token_hash=decode(?,'hex') AND t.expires_at>now() AND c.organization_id=? AND loc.status='ACTIVE' AND loc.site_mode='DELIVERY_ONLY'
            AND l.capabilities->>'physical_commands_enabled'='true' AND l.capabilities->>'synthetic'='false'
            AND NOT EXISTS(SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=loc.id)",[hash('sha256',$token),getenv('ZPX_ORGANIZATION_ID') ?: '0'])->fetch(PDO::FETCH_ASSOC);
        if(!$r||!hash_equals($r['fingerprint'],hash('sha256',$r['api_key']."\0".$r['api_secret'])))throw new Failure(401,'CABINET_AUTH_REQUIRED','Cabinet token expired or configuration changed.');
        return ['id'=>$r['id'],'locker_id'=>$r['locker_id'],'cabinet_id'=>$r['cabinet_id']];
    }
}
