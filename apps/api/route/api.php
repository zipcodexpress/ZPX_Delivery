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
foreach (['locations'=>'locations','shipments'=>'shipments','operations/shipments'=>'operations','recipient-claims/challenges'=>'claim-challenge','recipient-claims'=>'claim'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request) => Zpx\Http\ShippingController::handle($request,$action));
}
foreach (['shipments/<shipment>/payment-session'=>'payment','shipments/<shipment>/pending-payment'=>'pending-payment','development/payments/<shipment>/confirm'=>'confirm-payment','packages/<shipment>/labels'=>'labels','packages/<shipment>/label/pdf'=>'pdf','shipments/<shipment>'=>'get','shipments/<shipment>/tracking'=>'tracking','shipments/<shipment>/quotes'=>'quotes','shipments/<shipment>/cancel'=>'cancel','operations/shipments/<shipment>'=>'operations-get','operations/shipments/<shipment>/tracking'=>'operations-tracking'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request, string $shipment) => Zpx\Http\ShippingController::handle($request,$action,$shipment))->pattern(['shipment'=>'[1-9][0-9]{0,17}']);
}
Route::miss(static function () {
    $id = bin2hex(random_bytes(16));
    return Reply::json(404, ['code' => 'NOT_FOUND', 'message' => 'Endpoint not implemented.', 'request_id' => $id, 'retryable' => false], $id);
});
