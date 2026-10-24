<?php

declare(strict_types=1);

use App\Http\Controllers\External\TransferWallets;
use App\Http\Middleware\DetectIp;
use App\Http\Middleware\External\AddLogProfile;
use App\Http\Middleware\RequestLogger;
use Illuminate\Support\Facades\Route;

/*
 * |--------------------------------------------------------------------------
 * | For Transfer Wallets
 * |--------------------------------------------------------------------------
 * | 轉帳錢包
 * |
 */

$middlewares = [
    DetectIp::class,
    AddLogProfile::class,
    RequestLogger::class,
];

Route::middleware($middlewares)->prefix('tw')->group(function () {
    /** Ace333 APIs, 驗證遊戲帳號 */
    Route::post('ace333/authorize', [TransferWallets\Ace333Controller::class, 'authorize']);

    /** Mega888 APIs, 驗證遊戲帳號 */
    Route::post('mega888/login', [TransferWallets\Mega888Controller::class, 'login']);

    /** Ebet APIs, 驗證遊戲帳號 */
    Route::post('ebet/login', [TransferWallets\EbetController::class, 'login']);

    /** NextSpin APIs, 驗證遊戲帳號 */
    Route::post('nextspin/authorize', [TransferWallets\NextSpinController::class, 'authorize']);

    /** SpadeGaming APIs, 驗證遊戲帳號 */
    Route::post('spadegaming/authorize', [TransferWallets\SpadeGamingController::class, 'authorize']);

    /** Sky1388 APIs, 確認轉帳（防偽造上下分） */
    Route::post('sky1388/confirm_transfer', [TransferWallets\Sky1388Controller::class, 'confirmTransfer']);

    /** Pgslot APIs, 驗證遊戲帳號 */
    // Route::post('pgslot/authorize', [TransferWallets\PgslotController::class, 'authorize']);
});
