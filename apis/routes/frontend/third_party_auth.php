<?php

declare(strict_types=1);

use App\Http\Controllers\Frontend as FrontendControllers;
use App\Http\Middleware;
use App\Http\Middleware\Frontend as FrontendMiddlewares;
use App\Http\Middleware\Frontend\ThirdParty as ThirdPartyMiddlewares;
use App\Http\Middleware\RequestLogger;
use Illuminate\Support\Facades\Route;

$middlewares = [
    ThirdPartyMiddlewares\ThirdPartyDecryptRequest::class,
    Middleware\DetectIp::class,
    Middleware\DetectDevice::class,
    FrontendMiddlewares\DetectLanguage::class,
    ThirdPartyMiddlewares\ThirdPartyAuthMember::class,
    FrontendMiddlewares\CheckAgentMaintain::class,
    FrontendMiddlewares\MergeUserData::class,
    RequestLogger::class,
];

Route::middleware($middlewares)->prefix('third-party')->group(function () {
    Route::post('info', [FrontendControllers\ThirdPartyController::class, 'info']);
    Route::post('member-transfer', [FrontendControllers\ThirdPartyController::class, 'memberTransfer']);
    Route::post('agent-info', [FrontendControllers\ThirdPartyController::class, 'agentInfo']);
    Route::post('login-setting', [FrontendControllers\ThirdPartyController::class, 'getLoginSetting']);
    // 客服後台登入回報不受代理前台維護影響（target/tickets#4535），維護期間也要計數
    Route::post('agent-login-failed', [FrontendControllers\ThirdPartyController::class, 'agentLoginFailed'])
        ->withoutMiddleware(FrontendMiddlewares\CheckAgentMaintain::class);
    Route::post('agent-login-succeeded', [FrontendControllers\ThirdPartyController::class, 'agentLoginSucceeded'])
        ->withoutMiddleware(FrontendMiddlewares\CheckAgentMaintain::class);
    Route::post('check-agent-token', [FrontendControllers\ThirdPartyController::class, 'checkAgentToken']);
    Route::post('check-password', [FrontendControllers\ThirdPartyController::class, 'check_password']);
    Route::post('check-flow-limit', [FrontendControllers\ThirdPartyController::class, 'checkFlowLimit']);
    Route::post('bank-list', [FrontendControllers\ThirdPartyController::class, 'getBankList']);
    Route::post('cs-setting', [FrontendControllers\ThirdPartyController::class, 'csSetting']);
    Route::post('google-auth', [FrontendControllers\ThirdPartyController::class, 'googleAuth']);
    Route::post('send-sms', [FrontendControllers\ThirdPartyController::class, 'sendSms']);
    Route::post('check-code', [FrontendControllers\ThirdPartyController::class, 'checkCode']);
    Route::post('transaction', [FrontendControllers\ThirdPartyController::class, 'transaction']);
    Route::post('new-transaction', [FrontendControllers\ThirdPartyController::class, 'newTransaction']);
    Route::post('agent-transaction', [FrontendControllers\ThirdPartyController::class, 'agentTransaction']);
    Route::post('new-agent-transaction', [FrontendControllers\ThirdPartyController::class, 'newAgentTransaction']);
    Route::prefix('live-chat')->group(function () {
        Route::post('get-token', [FrontendControllers\ThirdPartyController::class, 'liveChatGetToken']);
        Route::post('get-fresh-token', [FrontendControllers\ThirdPartyController::class, 'liveChatGetFreshToken']);
        Route::post('has-token', [FrontendControllers\ThirdPartyController::class, 'liveChatHasToken']);
        Route::post('invalidate', [FrontendControllers\ThirdPartyController::class, 'liveChatInvalidate']);
    });
});
