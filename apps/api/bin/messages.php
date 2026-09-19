<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Zpx\Identity\Secrets;
use Zpx\Infrastructure\Database\Connection;
if (getenv('APP_ENV')!=='development') { fwrite(STDERR,"Local inbox is development-only.\n"); exit(1); }
$db=Connection::fromEnvironment();
if ($db->query('SELECT current_database()')->fetchColumn()!=='zpx_delivery_dev') { exit(1); }
$crypto=new Secrets();
$rows=$db->query("SELECT o.payload,v.expires_at FROM outbox_events o JOIN verification_challenges v ON v.id=o.aggregate_id WHERE o.event_type='identity.contact_verification' AND v.consumed_at IS NULL AND v.expires_at>now() AND v.attempts<5 ORDER BY o.id DESC LIMIT 100");
$messages=[];
foreach ($rows as $row) {
    $payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
    $message=json_decode($crypto->decrypt($payload['encrypted_message']),true,512,JSON_THROW_ON_ERROR);
    $messages[]=$message+['expires_at'=>$row['expires_at']];
}
echo json_encode($messages,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
