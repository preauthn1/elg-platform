<?php

declare(strict_types=1);

use App\Http\Controllers\ApiController;
use App\Http\Middleware\RequestLogger;
use Illuminate\Support\Facades\Route;

$middlewares = [
    RequestLogger::class,
];

Route::middleware($middlewares)->group(function () {
    // 建立代收訂單
    Route::post('deposit-order', [ApiController::class, 'createDepositOrder']);
    // 查詢代收訂單
    Route::get('deposit-order', [ApiController::class, 'queryDepositOrder']);
    // 建立代付訂單
    Route::post('withdraw-order', [ApiController::class, 'createWithdrawOrder']);
    // 查詢代付訂單
    Route::get('withdraw-order', [ApiController::class, 'queryWithdrawOrder']);
});
