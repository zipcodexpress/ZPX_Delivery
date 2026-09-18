<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$requestId = bin2hex(random_bytes(16));
[$status, $body] = (new Zpx\Http\Kernel())->handle($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', $requestId);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Request-ID: ' . $requestId);
if ($status === 405) { header('Allow: GET'); }
http_response_code($status);
echo json_encode($body, JSON_THROW_ON_ERROR);
