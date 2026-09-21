<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

function assertHttp(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS: ' . $message . "\n";
}

foreach ([['GET','/health/live',200], ['POST','/health/live',405], ['GET','/health/live/extra',404], ['GET','/unknown',404]] as [$method,$path,$status]) {
    $app = new think\App(dirname(__DIR__));
    $app->debug(false);
    $request = new think\Request();
    $request->withServer(['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$path,'PATH_INFO'=>$path]);
    $response = $app->http->run($request);
    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    assertHttp($response->getCode() === $status, "$method $path through ThinkPHP returns $status");
    assertHttp(isset($body['request_id']) && $response->getHeader('X-Request-ID') === $body['request_id'], 'correlated request ID');
    assertHttp($response->getHeader('Cache-Control') === 'no-store', 'API responses cannot be cached');
    $app->http->end($response);
}
$response = (new Zpx\Http\ExceptionHandler($app))->render($request, new RuntimeException('private database credential example'));
assertHttp($response->getCode() === 500 && !str_contains($response->getContent(), 'credential'), 'framework failures return sanitized JSON');
