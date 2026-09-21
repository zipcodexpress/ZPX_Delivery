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
foreach (['register','login','challenges','verify-contact','refresh','logout'] as $action) {
    Route::any('api/delivery/v1/auth/'.$action, static fn(Request $request) => Zpx\Http\IdentityController::handle($request,$action));
}
Route::any('api/delivery/v1/me', static fn(Request $request) => Zpx\Http\IdentityController::handle($request,'me'));
Route::miss(static function () {
    $id = bin2hex(random_bytes(16));
    return Reply::json(404, ['code' => 'NOT_FOUND', 'message' => 'Endpoint not implemented.', 'request_id' => $id, 'retryable' => false], $id);
});
