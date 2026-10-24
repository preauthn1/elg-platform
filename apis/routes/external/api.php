<?php

declare(strict_types=1);

use App\Http\Controllers\External\CashFlow;
use App\Http\Middleware\DetectIp;
use App\Http\Middleware\External\AddLogProfile;
use App\Http\Middleware\RequestLogger;
use Illuminate\Support\Facades\Route;

$middlewares = [
    DetectIp::class,
    AddLogProfile::class,
    RequestLogger::class,
];

Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
    ]);
});

Route::middleware($middlewares)->group(function () {
    /**
     * 三方代收代付回調
     * https://example.invalid/external/api/deposit/notify/155
     *
     * 🔴 **這兩條路由刻意不掛 throttle**（target/tickets#4449 一度掛過，上線前移除）。
     * 原本的設計是「白名單內不限流、其餘每分鐘 N 次」，但只有四家配了 cash_flows.whitelist，
     * 其餘二十幾家的真回調全都落在白名單外。2026-10-12 以 GCP log 實測，金流商普遍用一兩個
     * 出口 IP 服務整個站：TPower 單一 IP 單分鐘尖峰 112 筆、Yes2Win 120 筆，近八成的分鐘
     * 都超過 10 筆——掛上去就是把真回調擋掉，代價是玩家付了錢沒上分。
     *
     * 要再加流量保護的話，門檻必須先量過真實尖峰再訂，而且別把「沒配白名單」當成「可疑來源」。
     */
    Route::any('deposit/notify/{agentCashFlowId?}', CashFlow\DepositNotifyController::class);
    Route::any('withdrawal/notify/{agentCashFlowId?}', CashFlow\WithdrawalNotifyController::class);

    /**
     * 三方反查代付訂單狀態
     * https://example.invalid/external/api/withdrawal/query/155
     */
    Route::any('withdrawal/query/{agentCashFlowId?}', CashFlow\WithdrawalQueryController::class);
    Route::any('cash-flow/balance/{agentCashFlowId?}', CashFlow\GetBalanceController::class);
});
