<?php
declare(strict_types=1);
namespace Zpx\Infrastructure\Database;
use PDO;
use RuntimeException;
final class Connection
{
    public static function fromEnvironment(): PDO
    {
        $password = getenv('DB_PASSWORD');
        if (!$password || $password === 'GENERATE_WITH_DEV_SCRIPT') { throw new RuntimeException('Missing database secret'); }
        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'zpx_delivery_dev';
        $user = getenv('DB_USER') ?: 'zpx_runtime';
        $ssl = getenv('DB_SSLMODE') ?: 'prefer';
        foreach ([$host, $port, $name, $ssl] as $value) {
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $value)) { throw new RuntimeException('Invalid database configuration'); }
        }
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name;sslmode=$ssl;connect_timeout=5", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true]);
        $pdo->exec("SET timezone = 'UTC'; SET search_path TO delivery, pg_catalog; SET statement_timeout = '15s'; SET lock_timeout = '5s'");
        return $pdo;
    }
}
