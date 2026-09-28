<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets};
use Zpx\Infrastructure\Database\Transaction;

final class PeopleEditor
{
    public function __construct(private PDO $db,private Secrets $crypto) {}
    private function q(string $sql,array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function customer(string $actor,string $user,bool $lock=false): void
    {
        (new Access($this->db))->requireNetworkAdmin($actor);
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$user)) { throw new Failure(422,'INVALID_ID','Invalid customer ID.'); }
        $q="SELECT u.id FROM users u WHERE u.id=? AND u.organization_id=? AND EXISTS(
            SELECT 1 FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id WHERE g.user_id=u.id
            AND g.organization_id=u.organization_id AND r.code='CUSTOMER')".($lock?' FOR UPDATE OF u':'');
        if (!$this->q($q,[$user,$this->org()])->fetchColumn()) { throw new Failure(404,'CUSTOMER_NOT_FOUND','Customer not found.'); }
    }
    public function addresses(string $actor,string $user): array
    {
        $this->customer($actor,$user);
        $rows=$this->q('SELECT id,kind,label,address_ciphertext FROM user_addresses WHERE user_id=? AND archived_at IS NULL ORDER BY id',[$user])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function($row) {
            $row['address']=json_decode($this->crypto->decrypt($row['address_ciphertext']),true,512,JSON_THROW_ON_ERROR);
            unset($row['address_ciphertext']); return $row;
        },$rows);
    }
    public function save(string $actor,string $user,array $input): void
    {
        Input::fields($input,['address_id','kind','label','address','reason']);
        $id=$input['address_id'];
        if (!is_string($id) || ($id!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$id))) { throw new Failure(422,'INVALID_ID','Invalid address ID.'); }
        if (!in_array($input['kind'],['PROFILE','RETURN','BILLING'],true)) { throw new Failure(422,'INVALID_INPUT','Invalid address purpose.'); }
        $label=trim(Input::text($input['label'],0,80));
        $address=Input::address($input['address']);
        $reason=Input::text(trim(Input::text($input['reason'],10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$user,$input,$id,$label,$address,$reason) {
            $this->customer($actor,$user,true);
            $cipher=$this->crypto->encrypt(json_encode($address,JSON_THROW_ON_ERROR));
            if ($id==='') {
                $id=(string)$this->q('INSERT INTO user_addresses(user_id,kind,label,address_ciphertext,country_code,key_version)
                    VALUES (?,?,?,?,?,1) RETURNING id',[$user,$input['kind'],$label,$cipher,$address['country_code']])->fetchColumn();
                $action='CUSTOMER_ADDRESS_ADDED';
            } else {
                $current=$this->q('SELECT kind FROM user_addresses WHERE id=? AND user_id=? AND archived_at IS NULL FOR UPDATE',[$id,$user])->fetch(PDO::FETCH_ASSOC);
                if (!$current) { throw new Failure(404,'ADDRESS_NOT_FOUND','Address not found.'); }
                if ($current['kind']==='PROFILE' && $input['kind']!=='PROFILE'
                    && (int)$this->q("SELECT count(*) FROM user_addresses WHERE user_id=? AND kind='PROFILE' AND archived_at IS NULL",[$user])->fetchColumn()<=1) {
                    throw new Failure(409,'PRIMARY_ADDRESS_REQUIRED','Keep at least one profile address.');
                }
                $updated=$this->q('UPDATE user_addresses SET kind=?,label=?,address_ciphertext=?,country_code=?
                    WHERE id=? AND user_id=? AND archived_at IS NULL RETURNING id',[$input['kind'],$label,$cipher,$address['country_code'],$id,$user])->fetchColumn();
                if (!$updated) { throw new Failure(404,'ADDRESS_NOT_FOUND','Address not found.'); }
                $action='CUSTOMER_ADDRESS_UPDATED';
            }
            $this->q('INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,?,?,?,?)',
                [$actor,$action,'customer',$user,$reason.' [address '.$id.']']);
        });
    }
    public function archive(string $actor,string $user,string $id,string $reason): void
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$id)) { throw new Failure(422,'INVALID_ID','Invalid address ID.'); }
        $reason=Input::text(trim(Input::text($reason,10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$user,$id,$reason) {
            $this->customer($actor,$user,true);
            $row=$this->q('SELECT kind FROM user_addresses WHERE id=? AND user_id=? AND archived_at IS NULL FOR UPDATE',[$id,$user])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'ADDRESS_NOT_FOUND','Address not found.'); }
            if ($row['kind']==='PROFILE' && (int)$this->q("SELECT count(*) FROM user_addresses WHERE user_id=? AND kind='PROFILE' AND archived_at IS NULL",[$user])->fetchColumn()<=1) {
                throw new Failure(409,'PRIMARY_ADDRESS_REQUIRED','Keep at least one profile address.');
            }
            $this->q('UPDATE user_addresses SET archived_at=now() WHERE id=?',[$id]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'CUSTOMER_ADDRESS_ARCHIVED','customer',?,?)",[$actor,$user,$reason.' [address '.$id.']']);
        });
    }
    public function rename(string $actor,string $user,string $name,string $reason): void
    {
        $name=trim(Input::text($name,1,160));
        if ($name==='') { throw new Failure(422,'INVALID_INPUT','Name is required.'); }
        $reason=Input::text(trim(Input::text($reason,10,500)),10,500);
        (new Transaction($this->db))->run(function() use($actor,$user,$name,$reason) {
            $this->customer($actor,$user,true);
            $this->q('UPDATE users SET display_name=? WHERE id=?',[$name,$user]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'CUSTOMER_NAME_UPDATED','customer',?,?)",[$actor,$user,$reason]);
        });
    }
}
