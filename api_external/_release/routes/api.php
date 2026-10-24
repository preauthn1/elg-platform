<?php
/*
 * |--------------------------------------------------------------------------
 * | API Routes
 * |--------------------------------------------------------------------------
 * |
 * | Here is where you can register API routes for your application. These
 * | routes are loaded by the RouteServiceProvider within a group which
 * | is assigned the "api" middleware group. Enjoy building your API!
 * |
 */

/* 登入前可使用的API(沒驗證TOKEN) */
Route::group([
    'middleware' => 'none_auth'
], function () {
    /* ACE333登入 */
    Route::post('authenticate', 'Ace333Controller@authenticate');

    /* Mega888登入 */
    Route::post('mega888/login', 'Mega888Controller@authenticate');

    /* Ebet登入 */
    Route::post('ebet/login', 'EbetController@authenticate');

    /* Fishing登入驗證 */
    Route::post('fishing/account_auth', 'FishingController@authenticate');

    /* SpadeGaming登入驗證 */
    Route::post('spadegaming/authorize', 'SpadeGamingController@authenticate');

    /* NextSpin登入驗證 */
    Route::post('nextspin/authorize', 'NextSpinController@authenticate');

    /* PGS單錢包API */
    Route::prefix('target')->group(function () {
        /* 取得遊戲錢包金額 */
        Route::post('players/wallet', 'PgsController@getBalance');
        /* 玩家投注 */
        Route::post('bet/placeBet', 'PgsController@placeBet');
        /* 遊戲結算(一般投注) */
        Route::post('bet/settle', 'PgsController@settle');
        /* 遊戲結算(bonus獎勵/免費遊戲) */
        Route::post('bonus/settle', 'PgsController@bonus');
        /* 取消投注 */
        Route::post('bet/cancel', 'PgsController@cancel');
        /* 結算改單 */
        Route::post('bet/settle-change', 'PgsController@settle_change');
    });

    /* NCPAY */
    Route::post('ncpay', 'NcpayController@callback');

    /* PG */
    Route::post('pg/record/insert', 'PgController@insertbetrecord');

    /* ATPay */
    Route::post('ATPay/withdrawal/callback', 'ATPayController@WihtdrawalCallback');

    /* GoldPay */
    Route::post('GoldPay/withdrawal/callback', 'GoldPayController@WihtdrawalCallback');

    /* AllPay Withdrawal */
    Route::post('AllPay/withdrawal/callback', 'AllPayController@WihtdrawalCallback');


    /* 支付回調 */
    Route::match(['get', 'post'], 'cashflow/{agent_cash_flow_id}', 'CashFlowController@Callback');

    /* 支付回調 (AsiaLife特製) */
    Route::match(['get', 'post'], 'cashflow/callback/asiaLife', 'CashFlowController@AsiaLifeCallback');

    /* 測試debug用 */
    Route::match(['get', 'post'], 'RDDebug/TG/Message', 'RDDebugController@testTelegramMessage');

    /* 預付網 */
    Route::prefix('ota')->group(function () {
        /*預付網訂單確認*/
        Route::post('ota_request_payment', 'OtaController@requestPayment');
        /*預付網回調*/
        Route::post('ota_order_status', 'OtaController@orderStatus');
    });

    // /* 鬥雞 Place Bet*/
    // Route::post('CokeFighting/PlaceBet', 'CokeFightingController@placeBet');

    // /* 鬥雞 Cancel Bet*/
    // Route::post('CokeFighting/CancelBet', 'CokeFightingController@cancelBet');

    // /* 鬥雞 Update Bet*/
    // Route::post('CokeFighting/UpdateBet', 'CokeFightingController@updateBet');

    // /* 鬥雞 Settle Match*/
    // Route::post('CokeFighting/SettleMatch', 'CokeFightingController@settleMatch');

    // /* 鬥雞 Void Late Close*/
    // Route::post('CokeFighting/VoidLateClose', 'CokeFightingController@voidLateClose');

    // /* 鬥雞 Refund*/
    // Route::post('CokeFighting/Refund', 'CokeFightingController@refund');

    // /* 鬥雞 Get Balance*/
    // Route::post('CokeFighting/GetBalance', 'CokeFightingController@getBalance');

    //轉傳: 遊戲商錢包轉回功能
    Route::post('rd/transfer_in', 'RepostController@setTransferIn');
    Route::post('rd/balance', 'RepostController@getBalance');
});