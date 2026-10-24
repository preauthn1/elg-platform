<?php

declare(strict_types=1);

use App\Http\Controllers\Frontend\DebugAllPayController;
use App\Http\Controllers\Frontend\DebugController;
use App\Http\Middleware;
use App\Http\Middleware\Frontend as FrontendMiddlewares;
use App\Http\Middleware\LocalDebugOnly;
use Illuminate\Support\Facades\Route;

$middlewares = [
    LocalDebugOnly::class,
    Middleware\DetectIp::class,
    Middleware\DetectDevice::class,
    FrontendMiddlewares\DetectLanguage::class,
    FrontendMiddlewares\HasMember::class,
    FrontendMiddlewares\HasDomain::class,
    FrontendMiddlewares\MergeUserData::class,
];

Route::middleware($middlewares)->group(function () {
    /* 內部測試小工具 */
    Route::prefix('debug')->group(function () {
        Route::post('AES/decrypt', [DebugController::class, 'decryptRequest']);
        Route::post('AES/encrypt', [DebugController::class, 'encryptRequest']);
        Route::post('ip', [DebugController::class, 'getIP']);
        Route::post('test-queue', [DebugController::class, 'testQueue']);
        Route::any('try', [DebugController::class, 'try']);
        Route::post('wallets', [DebugController::class, 'wallets']);
        Route::post('transfer', [DebugController::class, 'transfer']);
        Route::post('try-deposit', [DebugController::class, 'tryDeposit']);
        Route::post('try-sms', [DebugController::class, 'trySendSms']);
        Route::post('allpay', [DebugAllPayController::class, 'tryAllPay']);
        Route::post('CS/decrypt', [DebugController::class, 'encryptCsData']);
        Route::get('redis/clear', [DebugController::class, 'redisClear']);
    });

    Route::prefix('bloom-filter')->group(function () {
        Route::post('check', [DebugController::class, 'checkBloomFilter']);
    });
});
