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
Route::any('api/delivery/v1/admin/access', static fn(Request $request) => ZpxAdmin\AccessController::handle($request));
Route::any('api/delivery/v1/admin/customers', static fn(Request $request) => ZpxAdmin\CustomerController::handle($request,'list'));
Route::any('api/delivery/v1/admin/customers/<user_id>',
    static fn(Request $request, string $userId) => ZpxAdmin\CustomerController::handle($request,'detail',$userId))
    ->pattern(['user_id'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/admin/customers/<user_id>/restrictions',
    static fn(Request $request, string $userId) => ZpxAdmin\CustomerController::handle($request,'restrict',$userId))
    ->pattern(['user_id'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/admin/customers/<user_id>/restrictions/<restriction_id>/revoke',
    static fn(Request $request, string $userId, string $restrictionId) => ZpxAdmin\CustomerController::handle($request,'revoke',$userId,$restrictionId))
    ->pattern(['user_id'=>'[1-9][0-9]{0,17}','restriction_id'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/admin/drivers', static fn(Request $request) => ZpxAdmin\DriverAdministrationController::handle($request,'list'));
Route::any('api/delivery/v1/admin/drivers/<driver_id>',
    static fn(Request $request, string $driverId) => ZpxAdmin\DriverAdministrationController::handle($request,'detail',$driverId))
    ->pattern(['driver_id'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/admin/drivers/<driver_id>/transitions',
    static fn(Request $request, string $driverId) => ZpxAdmin\DriverAdministrationController::handle($request,'transition',$driverId))
    ->pattern(['driver_id'=>'[1-9][0-9]{0,17}']);
foreach (['me/profile'=>'profile-update','me/payment-methods'=>'wallet-methods','me/payment-methods/manage'=>'wallet-manage','me/payments'=>'wallet-history','shipments/lookup'=>'lookup','locations'=>'locations','shipments'=>'shipments','operations/shipments'=>'operations','operations/packages/search'=>'operations-search','recipient-claims/challenges'=>'claim-challenge','recipient-claims'=>'claim'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request) => Zpx\Http\ShippingController::handle($request,$action));
}
foreach (['payments/<shipment>/hosted-session'=>'hosted-session','payments/<shipment>/reconcile'=>'reconcile-payment','shipments/<shipment>/payment-session'=>'payment','shipments/<shipment>/pending-payment'=>'pending-payment','development/payments/<shipment>/confirm'=>'confirm-payment','packages/<shipment>/labels'=>'labels','packages/<shipment>/label/pdf'=>'pdf','shipments/<shipment>'=>'get','shipments/<shipment>/tracking'=>'tracking','shipments/<shipment>/quotes'=>'quotes','shipments/<shipment>/cancel'=>'cancel','operations/shipments/<shipment>'=>'operations-get','operations/shipments/<shipment>/tracking'=>'operations-tracking','operations/shipments/<shipment>/payments'=>'operations-payments'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request, string $shipment) => Zpx\Http\ShippingController::handle($request,$action,$shipment))->pattern(['shipment'=>'[1-9][0-9]{0,17}']);
}
Route::any('api/delivery/v1/packages/<shipment>/origin-size-options', static fn(Request $request, string $shipment) => Zpx\Http\ShippingController::handle($request,'origin-size-options',$shipment))->pattern(['shipment'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/packages/<shipment>/origin-upgrade-quotes', static fn(Request $request, string $shipment) => Zpx\Http\ShippingController::handle($request,'origin-upgrade-quote',$shipment))->pattern(['shipment'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/packages/<shipment>/origin-upgrade-payment', static fn(Request $request, string $shipment) => Zpx\Http\ShippingController::handle($request,'origin-upgrade-payment',$shipment))->pattern(['shipment'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/development/origin-deposits', static fn(Request $request) => Zpx\Http\ShippingController::handle($request,'origin-deposit-start'));
Route::any('api/delivery/v1/development/origin-deposits/<shipment>/events', static fn(Request $request, string $shipment) => Zpx\Http\ShippingController::handle($request,'origin-deposit-event',$shipment))->pattern(['shipment'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/development/origin-deposits/<shipment>/confirm', static fn(Request $request, string $shipment) => Zpx\Http\ShippingController::handle($request,'origin-deposit-confirm',$shipment))->pattern(['shipment'=>'[1-9][0-9]{0,17}']);
foreach (['driver/runs'=>'runs','driver/pickup-offers'=>'pickup-offers','driver/pickup-offers/refresh'=>'pickup-refresh','driver/pickup-availability'=>'pickup-availability','driver/pickup-offers/<run_id>/accept'=>'pickup-accept','runs/<run_id>'=>'run-detail','runs/<run_id>/acknowledgments'=>'acknowledge','scans/resolve'=>'resolve','runs/<run_id>/scans'=>'scan','runs/<run_id>/depart'=>'depart'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request, string $runId='') => Zpx\Http\DriverController::handle($request,$action,$runId))->pattern(['run_id'=>'[1-9][0-9]{0,17}']);
}
Route::any('api/delivery/v1/runs/<run_id>/stops/<stop_id>/arrive', static fn(Request $request, string $runId, string $stopId) => Zpx\Http\DriverController::handle($request,'arrive',$runId,$stopId))->pattern(['run_id'=>'[1-9][0-9]{0,17}','stop_id'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/runs/<run_id>/stops/<stop_id>/final-deposits', static fn(Request $request, string $runId, string $stopId) => Zpx\Http\DriverController::handle($request,'final-deposit',$runId,$stopId))->pattern(['run_id'=>'[1-9][0-9]{0,17}','stop_id'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/devices/me/commands', static fn(Request $request) => Zpx\Http\DeviceController::commands($request));
foreach (['driver/register'=>'register','driver/profile'=>'profile','driver/profile/update'=>'update-profile','driver/wallet'=>'wallet','driver/transactions'=>'transactions','admin/drivers/pending'=>'pending','admin/drivers/<driver_id>/approve'=>'approve','admin/drivers/<driver_id>/reject'=>'reject','admin/driver-pay'=>'pay-run','admin/pickup-routes'=>'pickup-routes','admin/pickup-routes/assign'=>'pickup-route-assign','admin/pickup-recovery'=>'pickup-recovery'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request, string $driverId='') => Zpx\Http\DriverManagementController::handle($request,$action,$driverId))->pattern(['driver_id'=>'[1-9][0-9]{0,17}']);
}
Route::any('api/delivery/v1/admin/pickup-recovery/<run_id>/release', static fn(Request $request, string $runId) => Zpx\Http\DriverManagementController::handle($request,'pickup-release',$runId))->pattern(['run_id'=>'[1-9][0-9]{0,17}']);
Route::any('api/delivery/v1/admin/pickup-recovery/<run_id>/parcels/<package_id>/release', static fn(Request $request, string $runId, string $packageId) => Zpx\Http\DriverManagementController::handle($request,'pickup-item-release',$runId,$packageId))->pattern(['run_id'=>'[1-9][0-9]{0,17}','package_id'=>'[1-9][0-9]{0,17}']);
foreach (['hub/receiving-sessions'=>'open','hub/receiving-scans'=>'scan','hub/receiving-sessions/<session_id>/close'=>'close','hub/receiving-sessions/<session_id>'=>'status'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request, string $sessionId='') => Zpx\Http\HubReceivingController::handle($request,$action,$sessionId))->pattern(['session_id'=>'[1-9][0-9]{0,17}']);
}
foreach (['hub/receiving-discrepancies'=>'discrepancies','hub/receiving-discrepancies/<session_id>/resolve'=>'resolve-discrepancy'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request, string $sessionId='') => Zpx\Http\HubReceivingController::handle($request,$action,$sessionId))->pattern(['session_id'=>'[1-9][0-9]{0,17}']);
}
foreach (['hub/stage-scans'=>'stage','hub/dispatch-calls'=>'dispatch','hub/slots'=>'slots','hub/dispatch-calls/available'=>'available-calls','hub/dispatch-calls/<call_id>/accept'=>'accept'] as $path=>$action) {
    Route::any('api/delivery/v1/'.$path, static fn(Request $request, string $callId='') => Zpx\Http\HubDispatchController::handle($request,$action,$callId))->pattern(['call_id'=>'[1-9][0-9]{0,17}']);
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
require dirname(__DIR__, 2) . '/admin/routes.php';
Route::miss(static function () {
    $id = bin2hex(random_bytes(16));
    return Reply::json(404, ['code' => 'NOT_FOUND', 'message' => 'Endpoint not implemented.', 'request_id' => $id, 'retryable' => false], $id);
});
