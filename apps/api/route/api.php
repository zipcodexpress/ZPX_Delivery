<?php
use think\facade\Route;
use think\Request;
use Zpx\Http\Kernel;
use Zpx\Http\Reply;

$health = static function (Request $request) {
    $id = bin2hex(random_bytes(16));
    [$status, $body] = (new Kernel())->handle($request->method(true), '/' . $request->pathinfo(), $id);
    return Reply::json($status, $body, $id, $status === 405 ? ['Allow' => 'GET'] : []);
};
Route::any('health/live', $health);
Route::any('health/ready', $health);
Route::miss(static function () {
    $id = bin2hex(random_bytes(16));
    return Reply::json(404, ['code' => 'NOT_FOUND', 'message' => 'Endpoint not implemented.', 'request_id' => $id, 'retryable' => false], $id);
});
