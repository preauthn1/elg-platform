<?php

declare(strict_types=1);

use App\Http\Controllers\Frontend as FrontendControllers;
use App\Http\Middleware;
use App\Http\Middleware\Frontend as FrontendMiddlewares;
use App\Http\Middleware\RequestLogger;
use Illuminate\Support\Facades\Route;

$middlewares = [
    FrontendMiddlewares\DecryptRequest::class,
    Middleware\DetectIp::class,
    Middleware\DetectDevice::class,
    FrontendMiddlewares\DetectLanguage::class,
    FrontendMiddlewares\TGBot\TGBotAuthMember::class,
    FrontendMiddlewares\CheckAgentMaintain::class,
    FrontendMiddlewares\MergeUserData::class,
    RequestLogger::class,
];

Route::middleware($middlewares)->group(function () {
    Route::prefix('tgBot')->group(function () {
        Route::post('bindMobile', [FrontendControllers\TgBotController::class, 'bindMobile']);
    });
});
