<?php
declare(strict_types=1);
use Zpx\Development\Seed;

$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/pilot.json'), true, 512, JSON_THROW_ON_ERROR);
$seedNamespace = 'test-' . bin2hex(random_bytes(6));
$seed = new Seed($runtime, $seedNamespace);
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
    $seededPackages = $runtime->query("SELECT p.id FROM packages p JOIN shipments s ON s.id=p.shipment_id WHERE s.organization_id=$org AND p.state='AT_ORIGIN' ORDER BY p.id")->fetchAll(PDO::FETCH_COLUMN);
    $storedLabels = $runtime->query("SELECT encode(pl.token_hash,'hex') AS token_hash, pl.token_ciphertext FROM package_labels pl JOIN packages p ON p.id=pl.package_id JOIN shipments s ON s.id=p.shipment_id WHERE s.organization_id=$org ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC);
    check(array_column($storedLabels, 'token_hash') === array_map(fn(int $i) => hash('sha256', sprintf('TEST-LABEL-%03d', $i)), range(1, 5)), 'seed stores hashes for the five deterministic test labels');
    check(array_unique(array_column($storedLabels, 'token_ciphertext')) === [null], 'seed does not store test-label plaintext');
    $driver = $runtime->prepare("SELECT id FROM users WHERE organization_id=? AND external_auth_id=?");
    $driver->execute([$org, 'synthetic:' . $seedNamespace . ':DRIVER-IN']);
    $previousOrganization = getenv('ZPX_ORGANIZATION_ID');
    try {
        putenv('ZPX_ORGANIZATION_ID=' . $org);
        $custody = new Zpx\Custody\Service($runtime, new Zpx\Identity\Secrets());
        $driverUser = (string)$driver->fetchColumn();
        $resolved = $custody->resolveScan($driverUser, 'TEST-LABEL-001');
        $seededRun = (string)$runtime->query("SELECT id FROM route_runs WHERE organization_id=$org AND kind='INBOUND'")->fetchColumn();
        $runDetail = $custody->getRun($driverUser, $seededRun);
        check(array_column($runDetail['manifest'], 'development_label_token') === array_map(fn(int $i) => sprintf('TEST-LABEL-%03d', $i), range(1, 5)), 'development run manifest exposes its five synthetic label tokens');
        putenv('APP_ENV=production');
        $productionDetail = $custody->getRun($driverUser, $seededRun);
        check(!array_key_exists('development_label_token', $productionDetail['manifest'][0]), 'production run response omits synthetic label tokens');
        putenv('APP_ENV=test');
    } finally {
        putenv('APP_ENV=test');
        putenv($previousOrganization === false ? 'ZPX_ORGANIZATION_ID' : 'ZPX_ORGANIZATION_ID=' . $previousOrganization);
    }
    check($resolved['package_id'] === (string)$seededPackages[0], 'TEST-LABEL-001 resolves to the first seeded DRIVER-IN package');
    check((int)$runtime->query("SELECT count(*) FROM compartments c JOIN lockers k ON k.id=c.locker_id JOIN locations l ON l.id=k.location_id WHERE l.organization_id=$org AND c.status='FROZEN'")->fetchColumn() === 40, 'seed does not commission physical doors');
    $runtime->exec("UPDATE users SET display_name='Preserve local edit' WHERE organization_id=$org");
    $runtime->exec("UPDATE package_labels SET token_hash=decode(repeat('ab',32),'hex') WHERE package_id={$seededPackages[0]}");
    $again = $seed->run($fixture);
    check(!$again['created'] && $again['credentials'] === [] && $again['organization_id'] === $org, 'repeat seed preserves credentials without redisplaying');
    check((int)$runtime->query("SELECT count(*) FROM users WHERE organization_id=$org AND display_name='Preserve local edit'")->fetchColumn() === 6, 'repeat seed preserves local edits');
    check((string)$runtime->query("SELECT encode(token_hash,'hex') FROM package_labels WHERE package_id={$seededPackages[0]}")->fetchColumn() === hash('sha256', 'TEST-LABEL-001'), 'repeat seed upgrades a legacy random DRIVER-IN label hash');
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
