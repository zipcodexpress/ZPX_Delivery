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
foreach (['payments/<shipment>/hosted-session'=>'hosted-session','payments/<shipment>/reconcile'=>'reconcile-payment','shipments/<shipment>/payment-session'=>'payment','shipments/<shipment>/pending-payment'=>'pending-payment','development/payments/<shipment>/confirm'=>'confirm-payment','packages/<shipment>/labels'=>'labels','packages/<shipment>/label/pdf'=>'pdf','shipments/<shipment>'=>'get','shipments/<shipment>/tracking'=>'tracking','shipments/<shipment>/quotes'=>'quotes','shipments/<shipment>/cancel'=>'cancel','operations/shipments/<shipment>'=>'operations-get','operations/shipments/<shipment>/tracking'=>'operations-tracking','operations/shipments/<shipment>/payments'=>'operations-payments'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request, string $shipment) => Zpx\Http\ShippingController::handle($request,$action,$shipment))->pattern(['shipment'=>'[1-9][0-9]{0,17}']);
}
Route::any('api/delivery/v1/integrations/payments/webhook', static function(Request $request) {
    $id=Zpx\Identity\Secrets::uuid();
    try {
        if ($request->method(true)!=='POST') { throw new Zpx\Identity\Failure(405,'METHOD_NOT_ALLOWED','Use POST.'); }
        $raw=$request->getInput();
        if (strlen($raw)>65536) { throw new Zpx\Identity\Failure(413,'REQUEST_TOO_LARGE','Notification too large.'); }
        $service=new Zpx\Payments\HostedCheckout(Zpx\Infrastructure\Database\Connection::fromEnvironment(),new Zpx\Identity\Secrets());
        return Reply::json(200,$service->webhook($raw,$request->header('x-anet-signature','')),$id);
    } catch (Zpx\Identity\Failure $e) { return Reply::json($e->status,['code'=>$e->errorCode,'message'=>$e->getMessage(),'correlation_id'=>$id,'retryable'=>$e->status===503],$id); }
});
Route::miss(static function () {
    $id = bin2hex(random_bytes(16));
    return Reply::json(404, ['code' => 'NOT_FOUND', 'message' => 'Endpoint not implemented.', 'request_id' => $id, 'retryable' => false], $id);
});
