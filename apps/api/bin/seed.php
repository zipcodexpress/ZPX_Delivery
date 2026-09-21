<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Zpx\Development\Seed;
use Zpx\Infrastructure\Database\Connection;
use Zpx\Infrastructure\Database\Migrator;
use Zpx\Infrastructure\Database\Transaction;

try {
    $db = Connection::fromEnvironment();
    (new Migrator($db, dirname(__DIR__) . '/database/migrations'))->assertCurrent();
    $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/pilot.json'), true, 512, JSON_THROW_ON_ERROR);
    $result = (new Transaction($db))->run(fn() => (new Seed($db))->run($fixture));
    // Output only after commit. Subsequent invocations never rotate or reveal credentials.
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Synthetic seed failed; no credentials displayed. Check environment, fixture and schema.\n");
    exit(1);
}
