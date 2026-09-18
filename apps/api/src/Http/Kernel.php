<?php
declare(strict_types=1);
namespace Zpx\Http;
use Zpx\Infrastructure\Database\Connection;
use Zpx\Infrastructure\Database\Migrator;
use Throwable;
final class Kernel
{
    public function handle(string $method, string $path, string $requestId): array
    {
        $errorBody = static fn(string $code, string $message, bool $retryable = false): array => ['code' => $code, 'message' => $message, 'request_id' => $requestId, 'retryable' => $retryable];
        if (!in_array($path, ['/health/live', '/health/ready'], true)) { return [404, $errorBody('NOT_FOUND', 'Endpoint not implemented.')]; }
        if ($method !== 'GET') { return [405, $errorBody('METHOD_NOT_ALLOWED', 'Use GET.')]; }
        $body = ['status' => 'ok', 'service' => 'zpx-delivery-api', 'stage' => 'foundation', 'request_id' => $requestId];
        if ($path === '/health/ready') {
            try {
                $db = Connection::fromEnvironment();
                (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->assertCurrent();
                $body += ['database' => 'ready', 'engine' => 'postgresql'];
            } catch (Throwable $error) {
                error_log('Readiness failed; request_id=' . $requestId);
                return [503, ['status' => 'unavailable'] + $errorBody('DATABASE_NOT_READY', 'Database unavailable or migrations not current.', true)];
            }
        }
        return [200, $body];
    }
}
