<?php

declare(strict_types=1);

use App\Http\Controllers\External\LivechatBot\GetBalanceController;
use App\Http\Controllers\External\LivechatBot\GetPromotionDetailController;
use App\Http\Controllers\External\LivechatBot\GetVipSettingController;
use App\Http\Controllers\External\LivechatBot\GetWithdrawBalanceController;
use App\Http\Controllers\External\LivechatBot\InquiryDepositWithdrawalController;
use App\Http\Controllers\External\LivechatBot\PromotionsController;
use App\Http\Controllers\External\LivechatBot\ReferralProgressController;
use App\Http\Middleware\DetectIp;
use App\Http\Middleware\External\AddLogProfile;
use App\Http\Middleware\External\LivechatBotApiKeyAuth;
use App\Http\Middleware\RequestLogger;
use Illuminate\Support\Facades\Route;

$middlewares = [
    DetectIp::class,
    AddLogProfile::class,
    RequestLogger::class,
    LivechatBotApiKeyAuth::class,
];

$usernamePattern = '[A-Za-z0-9_.-]+';

Route::middleware($middlewares)
    ->prefix('api-callback/livechat/bot')
    ->group(function () use ($usernamePattern) {
        Route::get('get-balance/{username}', GetBalanceController::class)
            ->where('username', $usernamePattern)
            ->name('livechat-bot.get-balance');

        Route::get('inquiry-deposit-withdrawal/{username}/{transaction_type}', InquiryDepositWithdrawalController::class)
            ->where('username', $usernamePattern)
            ->where('transaction_type', 'deposit|withdraw')
            ->name('livechat-bot.inquiry-deposit-withdrawal');

        Route::get('get-withdraw-balance/{username}', GetWithdrawBalanceController::class)
            ->where('username', $usernamePattern)
            ->name('livechat-bot.get-withdraw-balance');

        Route::get('promotions/{username}', PromotionsController::class)
            ->where('username', $usernamePattern)
            ->name('livechat-bot.promotions');

        Route::get('referral-progress/{username}', ReferralProgressController::class)
            ->where('username', $usernamePattern)
            ->name('livechat-bot.referral-progress');

        Route::get('get-vip-setting/{username}', GetVipSettingController::class)
            ->where('username', $usernamePattern)
            ->name('livechat-bot.get-vip-setting');

        Route::get('get-promotion-detail/{username}/{promotionName}', GetPromotionDetailController::class)
            ->where('username', $usernamePattern)
            ->name('livechat-bot.get-promotion-detail');
    });
