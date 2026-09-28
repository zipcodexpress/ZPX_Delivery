<?php
declare(strict_types=1);

use think\facade\Route;
use think\Request;
use ZpxAdmin\PageController;

Route::any('admin', static fn(Request $request) => PageController::handle($request, 'overview'));
Route::any('admin/login', static fn(Request $request) => PageController::handle($request, 'login'));
Route::any('admin/logout', static fn(Request $request) => PageController::handle($request, 'logout'));
Route::get('admin-style', static fn() => PageController::stylesheet());
Route::any('admin/action/<operation>', static fn(Request $request, string $operation) => PageController::action($request, $operation))
    ->pattern(['operation'=>'driver-approve|driver-reject|driver-suspend|driver-reactivate|route-assign|pickup-release|customer-restrict|customer-revoke|partner-create|site-create|site-update|site-relationship|site-contact-add|site-contact-archive|site-location-create|site-overdue|location-overdue|location-deactivate|location-reactivate|locker-body-add|locker-module-add|locker-box-add|locker-box-assign|customer-rename|address-save|address-archive']);
foreach (['customers', 'drivers', 'partners', 'sites', 'lockers', 'pickup-routes', 'pickup-recovery', 'shipments'] as $page) {
    Route::any('admin/'.$page, static fn(Request $request) => PageController::handle($request, $page));
}
Route::any('admin/customers/<user_id>', static fn(Request $request, string $userId) => PageController::handle($request,'customer-detail',$userId))
    ->pattern(['user_id'=>'[1-9][0-9]{0,17}']);
Route::any('admin/customers/<user_id>/history', static fn(Request $request, string $userId) => PageController::handle($request,'customer-history',$userId))
    ->pattern(['user_id'=>'[1-9][0-9]{0,17}']);
Route::any('admin/drivers/<driver_id>', static fn(Request $request, string $driverId) => PageController::handle($request,'driver-detail',$driverId))
    ->pattern(['driver_id'=>'[1-9][0-9]{0,17}']);
Route::any('admin/drivers/<driver_id>/history', static fn(Request $request, string $driverId) => PageController::handle($request,'driver-history',$driverId))
    ->pattern(['driver_id'=>'[1-9][0-9]{0,17}']);
Route::any('admin/partners/<partner_id>', static fn(Request $request, string $partnerId) => PageController::handle($request,'partner-detail',$partnerId))
    ->pattern(['partner_id'=>'[1-9][0-9]{0,17}']);
Route::any('admin/sites/<site_id>', static fn(Request $request, string $siteId) => PageController::handle($request,'site-detail',$siteId))
    ->pattern(['site_id'=>'[1-9][0-9]{0,17}']);
Route::any('admin/lockers/<locker_id>', static fn(Request $request, string $lockerId) => PageController::handle($request,'locker-detail',$lockerId))
    ->pattern(['locker_id'=>'[1-9][0-9]{0,17}']);
Route::any('admin/shipments/<shipment_id>', static fn(Request $request, string $shipmentId) => PageController::handle($request,'shipment-detail',$shipmentId))
    ->pattern(['shipment_id'=>'[1-9][0-9]{0,17}']);
