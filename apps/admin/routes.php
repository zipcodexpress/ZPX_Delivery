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
    ->pattern(['operation'=>'driver-approve|driver-reject|route-assign|pickup-release']);
foreach (['drivers', 'pickup-routes', 'pickup-recovery', 'shipments'] as $page) {
    Route::any('admin/'.$page, static fn(Request $request) => PageController::handle($request, $page));
}
