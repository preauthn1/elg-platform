<?php

declare(strict_types=1);

use App\Http\Controllers\Legacy\ApkCompatController;
use App\Http\Middleware;
use App\Http\Middleware\Frontend as FrontendMiddlewares;
use App\Http\Middleware\RequestLogger;
use Illuminate\Support\Facades\Route;

/**
 * 路徑固定 api/*，不收進 frontend/api/*。
 *
 * 中介層只掛解密與語系；HasMember、HasDomain、CheckAgentMaintain 這幾層
 * 這三支不需要，掛上去會把原本會過的請求擋掉。
 */
Route::middleware([
    FrontendMiddlewares\DecryptRequest::class,
    Middleware\DetectIp::class,
    FrontendMiddlewares\DetectLanguage::class,
    RequestLogger::class,
])->group(function () {
    /** 撈該 agent 域名 */
    Route::post('frontend_domain', [ApkCompatController::class, 'frontendDomain']);
    /** 檢查 APP 版本 */
    Route::post('versions', [ApkCompatController::class, 'versions']);
    /** 備用 API 網域 */
    Route::post('alternate_domain', [ApkCompatController::class, 'alternateDomain']);
});
