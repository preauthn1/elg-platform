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

    /* NCPAY */
    Route::post('ncpay', 'NcpayController@callback');

    /* PG */
    Route::post('pg/record/insert', 'PgController@insertbetrecord');

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
});