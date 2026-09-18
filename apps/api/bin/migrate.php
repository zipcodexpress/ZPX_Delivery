<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
try {
    $migrator = new Zpx\Infrastructure\Database\Migrator(Zpx\Infrastructure\Database\Connection::fromEnvironment(), dirname(__DIR__) . '/database/migrations');
    if (($argv[1] ?? 'up') === 'status') { $migrator->assertCurrent(); echo "Schema current.\n"; }
    elseif (($argv[1] ?? 'up') === 'up') { echo 'Applied migrations: ' . $migrator->up() . "\n"; }
    else { throw new RuntimeException('Usage: migrate.php up|status'); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
