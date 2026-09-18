<?php
declare(strict_types=1);

// Local foundation health surface, not the production business API.
header('Content-Type: application/json');
header('Cache-Control: no-store');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$status = 200;
$body = ['status' => 'ok', 'service' => 'zpx-delivery-api', 'stage' => 'foundation'];
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $status = 405;
    header('Allow: GET');
    $body = ['code' => 'METHOD_NOT_ALLOWED'];
} elseif ($path === '/health/ready') {
    try {
        $password = getenv('DB_PASSWORD');
        if (!$password) { throw new RuntimeException('Missing database configuration'); }
        $pdo = new PDO(
            'mysql:host=' . (getenv('DB_HOST') ?: 'mysql') . ';dbname=zpx_delivery_dev;charset=utf8mb4',
            'zpx_dev', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'zpx_delivery_dev' AND table_type = 'BASE TABLE'")->fetchColumn();
        if ($count !== 73) { throw new RuntimeException('Schema incomplete'); }
        $body['database'] = 'ready';
        $body['draft_tables'] = $count;
    } catch (Throwable $error) {
        $status = 503;
        $body = ['status' => 'unavailable', 'code' => 'DATABASE_NOT_READY', 'retryable' => true];
    }
} elseif ($path !== '/health/live') {
    $status = 404;
    $body = ['code' => 'NOT_FOUND', 'message' => 'Business endpoints are not implemented yet.'];
}
http_response_code($status);
echo json_encode($body, JSON_THROW_ON_ERROR);
