<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Zpx\Infrastructure\Database\Connection;
use Zpx\Infrastructure\Database\Migrator;
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;
use Zpx\Http\Kernel;

function check(bool $condition, string $name): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $name); }
    echo 'PASS: ' . $name . "\n";
}
function rejected(PDO $db, string $sql, string $state): void {
    $db->exec('SAVEPOINT rejection');
    try { $db->exec($sql); throw new RuntimeException('Expected SQL rejection: ' . $state); }
    catch (PDOException $error) { check($error->getCode() === $state, 'database rejected operation with ' . $state); }
    finally { $db->exec('ROLLBACK TO SAVEPOINT rejection'); }
}
function insertId(PDO $db, string $sql, array $args = []): string {
    $q = $db->prepare($sql . ' RETURNING id'); $q->execute($args); return (string)$q->fetchColumn();
}
function uuid(): string {
    $h=bin2hex(random_bytes(16)); return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
}
if (getenv('APP_ENV') !== 'test' || (getenv('DB_NAME') ?: 'zpx_delivery_dev') !== 'zpx_delivery_dev') { throw new RuntimeException('Tests require disposable development database'); }
$runtime = Connection::fromEnvironment();
if (($argv[1] ?? '') === '--refresh-race') {
    $q=$runtime->prepare("SELECT set_config('application_name',?,false)"); $q->execute([$argv[2]]);
    $token=trim((string)fgets(STDIN));
    echo "READY\n"; flush();
    try {
        (new Zpx\Identity\Service($runtime,new Zpx\Identity\Secrets()))->refresh(['refresh_token'=>$token]);
        echo "200\n";
    } catch (Zpx\Identity\Failure $e) { echo $e->status."\n"; }
    exit(0);
}
if (($argv[1] ?? '') === '--claim-race') {
    $q=$runtime->prepare("SELECT set_config('application_name',?,false)"); $q->execute([$argv[5]]);
    echo "READY\n"; flush();
    try {
        $q=$runtime->prepare("INSERT INTO compartment_claims(compartment_id,package_id,session_id,state) VALUES (?,?,?,'HELD')");
        $q->execute([$argv[2],$argv[3],$argv[4]]);
        exit(1);
    } catch (PDOException $e) { echo $e->getCode() . "\n"; exit($e->getCode()==='23505' ? 0 : 1); }
}
if (($argv[1] ?? '') === '--race') {
    echo "READY\n"; flush();
    try {
        $q=$runtime->prepare("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,'race',decode(repeat('dd',32),'hex'),200,'{}',now()+interval '1 minute')");
        $q->execute([$argv[2]]);
        echo "UNEXPECTED_SECOND_WINNER\n"; exit(1);
    } catch (PDOException $e) { echo $e->getCode() . "\n"; exit($e->getCode()==='23505' ? 0 : 1); }
}
$password = getenv('DB_PASSWORD');
putenv('DB_USER=zpx_migrator'); putenv('DB_PASSWORD=' . getenv('TEST_MIGRATION_PASSWORD'));
$owner = Connection::fromEnvironment();
putenv('DB_USER=zpx_runtime'); putenv('DB_PASSWORD=' . $password);
$dir = dirname(__DIR__) . '/database/migrations';
$migrator = new Migrator($owner, $dir);
check($migrator->up() === 0, 'repeated migrations apply nothing');
$migrator->assertCurrent();
check((int)$owner->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='delivery' AND table_type='BASE TABLE'")->fetchColumn() === 75, '73 business tables plus migration ledger and auth limiter');
require __DIR__ . '/seed.php';
require __DIR__ . '/ownership.php';

// Checksum drift and failed transactional DDL must not corrupt the schema/ledger.
$temp = sys_get_temp_dir() . '/zpx-migrations-' . bin2hex(random_bytes(8)); mkdir($temp);
try {
    foreach ($migrator->files() as $file) { copy($file, $temp . '/' . basename($file)); }
    $first = $temp . '/' . basename($migrator->files()[0]);
    file_put_contents($first, "\n-- tampered\n", FILE_APPEND);
    try { (new Migrator($owner, $temp))->up(); throw new LogicException('Drift accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'Migration checksum mismatch', 'checksum drift refused'); }
    copy($migrator->files()[0], $first);
    file_put_contents($temp . '/999_failure_probe.sql', 'CREATE TABLE migration_failure_probe(id INT); SELECT absent_column FROM migration_failure_probe;');
    try { (new Migrator($owner, $temp))->up(); throw new LogicException('Broken migration accepted'); }
    catch (PDOException $e) { check(in_array($owner->query("SELECT to_regclass('delivery.migration_failure_probe') IS NULL")->fetchColumn(), [true,'t','1'], true), 'failed DDL rolled back'); }
    $migrator->assertCurrent();
} finally { foreach (glob($temp . '/*') as $file) { unlink($file); } rmdir($temp); }

$runtime->beginTransaction();
try {
    $org = insertId($runtime, "INSERT INTO organizations(name) VALUES ('Synthetic integration organization')");
    $user = insertId($runtime, "INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?,'Synthetic user','ACTIVE')", [$org,uuid()]);
    $loc = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,status) VALUES (?,?,'Synthetic locker','LOCKER','Not a real address','ACTIVE')", [$org,uuid()]);
    $locker = insertId($runtime, 'INSERT INTO lockers(location_id) VALUES (?)', [$loc]);
    $door = insertId($runtime, "INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status) VALUES (?,'1',100,100,100,1000,'AVAILABLE')", [$locker]);
    $shipment = insertId($runtime, "INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES (?,?,?, ?,?,'STANDARD','DRAFT','UNPAID')", [$org,$user,uuid(),$loc,$loc]);
    $package = insertId($runtime, "INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref) VALUES (?,?,1,10,10,10,10,'CREATED','SENDER',?)", [$shipment,uuid(),$user]);
    $package2 = insertId($runtime, "INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref) VALUES (?,?,2,10,10,10,10,'CREATED','SENDER',?)", [$shipment,uuid(),$user]);
    $runtime->exec("INSERT INTO package_labels(package_id,label_version,token_hash,status) VALUES ($package,1,decode(repeat('aa',32),'hex'),'ACTIVE')");
    rejected($runtime, "INSERT INTO package_labels(package_id,label_version,token_hash,status) VALUES ($package,2,decode(repeat('bb',32),'hex'),'ACTIVE')", '23505');
    rejected($runtime, "INSERT INTO package_labels(package_id,label_version,token_hash,status) VALUES ($package2,1,decode('aa','hex'),'ACTIVE')", '23514');
    $session = insertId($runtime, "INSERT INTO locker_sessions(package_id,compartment_id,actor_user_id,action,status,expires_at,evidence_policy) VALUES (?,?,?,'DEPOSIT','PENDING',now()+interval '10 minutes','DOOR_AND_ACTOR')", [$package,$door,$user]);
    rejected($runtime, "INSERT INTO compartment_claims(compartment_id,package_id,session_id,state) VALUES ($door,$package2,$session,'HELD')", '23503');
    $runtime->exec("INSERT INTO compartment_claims(compartment_id,package_id,session_id,state) VALUES ($door,$package,$session,'HELD')");
    rejected($runtime, "INSERT INTO compartment_claims(compartment_id,package_id,session_id,state) VALUES ($door,$package,$session,'HELD')", '23505');
    rejected($runtime, "UPDATE packages SET weight_g=-1 WHERE id=$package", '23514');
    rejected($runtime, "UPDATE packages SET state='DELIVERED_BY_SCAN' WHERE id=$package", '23514');
    rejected($runtime, 'CREATE TABLE forbidden(id INT)', '42501');
    rejected($runtime, "DELETE FROM schema_migrations", '42501');
    rejected($runtime, "UPDATE compartment_ownership SET generation=99", '42501');
    $runtime->exec("INSERT INTO audit_events(action,entity_type,entity_id) VALUES ('TEST','package','$package')");
    rejected($runtime, "UPDATE audit_events SET action='REWRITE'", '42501');
    rejected($runtime, 'DELETE FROM audit_events', '42501');
    rejected($runtime, 'TRUNCATE audit_events', '42501');
    $runtime->exec("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES ('test','same',decode(repeat('cc',32),'hex'),200,'{}',now()+interval '1 minute')");
    rejected($runtime, "INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES ('test','same',decode(repeat('cc',32),'hex'),200,'{}',now()+interval '1 minute')", '23505');
} finally { $runtime->rollBack(); }

$marker = 'rollback-' . uuid(); $event = uuid();
// Two independent runtime connections contend for the same idempotency key.
$scope='race-' . uuid();
$runtime->beginTransaction();
$q=$runtime->prepare("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,'race',decode(repeat('dd',32),'hex'),200,'{}',now()+interval '1 minute')");
$q->execute([$scope]);
$child=proc_open([PHP_BINARY,__FILE__,'--race',$scope],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if (!is_resource($child)) { $runtime->rollBack(); throw new RuntimeException('Cannot start race contender'); }
try {
    stream_set_timeout($pipes[1],10);
    check(trim((string)fgets($pipes[1]))==='READY','concurrent runtime contender connected');
    usleep(100000);
    $runtime->commit();
    $output=stream_get_contents($pipes[1]);
    foreach ($pipes as $pipe) { fclose($pipe); }
    $exit=proc_close($child);
    check($exit===0 && trim($output)==='23505','concurrent duplicate has exactly one winner');
} finally {
    if ($runtime->inTransaction()) { $runtime->rollBack(); }
    $q=$runtime->prepare('DELETE FROM idempotency_records WHERE scope=?');$q->execute([$scope]);
}
try {
    (new Transaction($runtime))->run(function (PDO $db) use ($marker,$event): void {
        $id = insertId($db,'INSERT INTO organizations(name) VALUES (?)',[$marker]);
        (new Outbox($db))->append($event,'organization',$id,'test.created',[]);
        throw new RuntimeException('Simulated failure after event');
    });
} catch (RuntimeException $e) { check($e->getMessage()==='Simulated failure after event','transaction propagated failure'); }
$q=$runtime->prepare('SELECT count(*) FROM organizations WHERE name=?');$q->execute([$marker]);check((int)$q->fetchColumn()===0,'projection rollback');
$q=$runtime->prepare('SELECT count(*) FROM outbox_events WHERE event_uuid=?');$q->execute([$event]);check((int)$q->fetchColumn()===0,'outbox rollback');
try { (new Outbox($runtime))->append(uuid(),'organization','1','test.invalid',[]); throw new RuntimeException('Outbox accepted autocommit'); }
catch (LogicException $e) { check(true,'outbox requires transaction'); }

$kernel = new Kernel();
check($kernel->handle('GET','/health/ready','test')[0]===200,'runtime role readiness');
check($kernel->handle('POST','/health/live','test')[0]===405,'health method denied');
check($kernel->handle('GET','/not-implemented','test')[0]===404,'unknown route denied');
putenv('DB_PASSWORD=');
$failed=$kernel->handle('GET','/health/ready','test');
check($failed[0]===503 && $failed[1]['code']==='DATABASE_NOT_READY','unavailable database returns sanitized 503');
check($kernel->handle('GET','/health/live','test')[0]===200,'liveness independent of database');
putenv('DB_PASSWORD=' . $password);
require __DIR__ . '/identity.php';
echo "PostgreSQL foundation integration passed. No physical hardware tested.\n";
