<?php
declare(strict_types=1);
use Zpx\Development\Seed;

$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/pilot.json'), true, 512, JSON_THROW_ON_ERROR);
$seed = new Seed($runtime, 'test-' . bin2hex(random_bytes(6)));
$runtime->beginTransaction();
try {
    putenv('APP_ENV=production');
    try { $seed->run($fixture); throw new LogicException('Production seed accepted'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'explicit local'), 'seed refuses production'); }
    finally { putenv('APP_ENV=test'); }
    $result = $seed->run($fixture);
    $org = $result['organization_id'];
    check($result['created'] && count($result['credentials']) === 6, 'seed creates six synthetic credentials');
    foreach ($result['credentials'] as $credential) {
        $q = $runtime->prepare('SELECT password_hash FROM auth_credentials c JOIN users u ON u.id=c.user_id WHERE external_auth_id=?');
        $q->execute([$credential['identity']]);
        check(password_verify($credential['password'], $q->fetchColumn()), 'seed stores a verifiable password hash');
    }
    check((int)$runtime->query("SELECT count(*) FROM locations WHERE organization_id=$org")->fetchColumn() === 21, 'seed creates twenty sites and one hub');
    check((int)$runtime->query("SELECT count(*) FROM packages p JOIN shipments s ON s.id=p.shipment_id WHERE s.organization_id=$org AND p.state='CREATED' AND s.payment_status='UNPAID'")->fetchColumn() === 5, 'seed leaves five unpaid parcels with sender');
    check((int)$runtime->query("SELECT count(*) FROM packages p JOIN shipments s ON s.id=p.shipment_id WHERE s.organization_id=$org AND p.state='AT_ORIGIN' AND s.payment_status='PAID'")->fetchColumn() === 5, 'seed transitions five parcels to origin for driver pickup');
    check((int)$runtime->query("SELECT count(*) FROM route_runs WHERE organization_id=$org AND kind='INBOUND' AND state='PUBLISHED'")->fetchColumn() === 1, 'seed creates one published inbound run');
    check((int)$runtime->query("SELECT count(*) FROM manifest_items mi JOIN route_runs r ON r.id=mi.run_id WHERE r.organization_id=$org AND mi.state='EXPECTED'")->fetchColumn() === 5, 'seed creates five expected manifest items');
    check((int)$runtime->query("SELECT count(*) FROM compartments c JOIN lockers k ON k.id=c.locker_id JOIN locations l ON l.id=k.location_id WHERE l.organization_id=$org AND c.status='FROZEN'")->fetchColumn() === 40, 'seed does not commission physical doors');
    $runtime->exec("UPDATE users SET display_name='Preserve local edit' WHERE organization_id=$org");
    $again = $seed->run($fixture);
    check(!$again['created'] && $again['credentials'] === [] && $again['organization_id'] === $org, 'repeat seed preserves credentials without redisplaying');
    check((int)$runtime->query("SELECT count(*) FROM users WHERE organization_id=$org AND display_name='Preserve local edit'")->fetchColumn() === 6, 'repeat seed preserves local edits');
} finally { $runtime->rollBack(); }
$q = $runtime->prepare('SELECT count(*) FROM organizations WHERE id=?'); $q->execute([$org]);
check((int)$q->fetchColumn() === 0, 'seed transaction rollback leaves no fixture records');
$failedNamespace = 'fail-' . bin2hex(random_bytes(6));
$invalidFixture = $fixture;
$invalidFixture['locations'][0]['site_mode'] = 'INVALID';
try {
    (new Zpx\Infrastructure\Database\Transaction($runtime))->run(fn() => (new Seed($runtime, $failedNamespace))->run($invalidFixture));
    throw new LogicException('Invalid fixture accepted');
} catch (PDOException $e) { check($e->getCode() === '23514', 'invalid seed fixture rejected'); }
$q = $runtime->prepare('SELECT count(*) FROM organizations WHERE name=?');
$q->execute([Seed::ORGANIZATION . ':' . $failedNamespace]);
check((int)$q->fetchColumn() === 0, 'failed seed rolls back accounts and topology together');
