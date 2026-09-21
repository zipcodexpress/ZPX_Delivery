<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Zpx\Development\Seed;
use Zpx\Identity\Secrets;
use Zpx\Infrastructure\Database\{Connection,Transaction};

if (getenv('APP_ENV')!=='development') { fwrite(STDERR,"Demo identity setup is development-only.\n"); exit(1); }
$db=Connection::fromEnvironment();
if ($db->query('SELECT current_database()')->fetchColumn()!=='zpx_delivery_dev') { exit(1); }
$secrets=new Secrets();
$result=(new Transaction($db))->run(function () use ($db,$secrets) {
    $db->query('SELECT pg_advisory_xact_lock(762940118)');
    $q=$db->prepare('SELECT id FROM organizations WHERE name=? AND id=?');
    $q->execute([Seed::ORGANIZATION.':local',getenv('ZPX_ORGANIZATION_ID')]); $org=$q->fetchColumn();
    if (!$org) { throw new RuntimeException('Initialize the synthetic organization first'); }
    $q=$db->prepare("SELECT id,external_auth_id FROM users WHERE organization_id=? AND external_auth_id LIKE 'synthetic:local:%' ORDER BY external_auth_id");
    $q->execute([$org]); $users=$q->fetchAll(PDO::FETCH_ASSOC);
    if (count($users)!==6) { throw new RuntimeException('Expected six existing synthetic identities'); }
    $created=0;
    foreach ($users as $i=>$user) {
        $email=strtolower(substr($user['external_auth_id'],strlen('synthetic:local:'))).'.local@example.invalid';
        // Reserved fictional US numbers, never sent to a provider by this command.
        $phone='+1202555019'.$i;
        foreach (['EMAIL'=>$email,'PHONE'=>$phone] as $kind=>$value) {
            $check=$db->prepare('SELECT id FROM user_contacts WHERE user_id=? AND kind=?'); $check->execute([$user['id'],$kind]);
            if ($check->fetchColumn()) { continue; }
            $insert=$db->prepare("INSERT INTO user_contacts(user_id,kind,value_ciphertext,lookup_hmac,key_version) VALUES (?,?,?,decode(?,'hex'),1)");
            $insert->execute([$user['id'],$kind,$secrets->encrypt($value),$secrets->digest('contact:'.$kind,$value)]); $created++;
        }
    }
    return $created;
});
echo 'Added synthetic contacts: '.$result.". Existing passwords and roles preserved.\n";
