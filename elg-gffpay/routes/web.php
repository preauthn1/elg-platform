<?php

declare(strict_types=1);

use App\Http\Controllers\FrontendController;
use App\Http\Middleware\RequestLogger;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

$middlewares = [
    RequestLogger::class,
];

Route::get('/', function () {
    return view('welcome');
});

Route::middleware($middlewares)->group(function () {
    // 預覽付款頁面（不產生訂單）
    Route::get('cashier/preview', [FrontendController::class, 'preview']);
    // 用戶付款頁面
    Route::get('cashier/{orderNo}', [FrontendController::class, 'cashier']);
});

if (app()->bound('scramble.boot.ready')) {
    Scramble::registerUiRoute('docs/frontend', api: 'frontend');
    Scramble::registerJsonSpecificationRoute('docs/frontend/api.json', api: 'frontend');

    Scramble::registerUiRoute('docs/agent', api: 'agent');
    Scramble::registerJsonSpecificationRoute('docs/agent/api.json', api: 'agent');

    Scramble::registerUiRoute('docs/external', api: 'external');
    Scramble::registerJsonSpecificationRoute('docs/external/api.json', api: 'external');
}
