<?php

declare(strict_types=1);

use App\Http\Controllers\External\SingleWallets;
use App\Http\Middleware\DetectIp;
use App\Http\Middleware\External\AddLogProfile;
use App\Http\Middleware\External\PasswordProtected;
use App\Http\Middleware\External\SingleWallets as SingleWalletsMiddlewares;
use App\Http\Middleware\RequestLogger;
use App\Models\Vendor;
use App\Services\VendorService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

/*
 * |--------------------------------------------------------------------------
 * | For Seamless Wallets
 * |--------------------------------------------------------------------------
 * | 無縫錢包 (單錢包)
 * |
 */

$middlewares = [
    DetectIp::class,
    AddLogProfile::class,
    RequestLogger::class,
];

Route::middleware($middlewares)->prefix('sw')->group(function () {
    /** Mega win */
    Route::middleware(SingleWalletsMiddlewares\MegaWinRequestDecrypt::class)
        ->prefix('megawin')
        ->group(function () {
            Route::post('AuthenticateToken', [SingleWallets\MegawinSWController::class, 'authenticateToken']);
            Route::post('PlaceBet', [SingleWallets\MegawinSWController::class, 'placeBet']);
            Route::post('SettleBet', [SingleWallets\MegawinSWController::class, 'settleBet']);
            Route::post('UnsettleBet', [SingleWallets\MegawinSWController::class, 'unsettleBet']);
            Route::post('GetBalance', [SingleWallets\MegawinSWController::class, 'getBalance']);
        });

    /** Clot play */
    Route::prefix('clot')->group(function () {
        Route::get('api/seamless/getBalance', [SingleWallets\ClotPlayController::class, 'balance']);
        Route::post('api/seamless/betSettlement', [SingleWallets\ClotPlayController::class, 'betAndSettle']);
        Route::post('api/seamless/cancel', [SingleWallets\ClotPlayController::class, 'cancel']);
        Route::post('api/seamless/bet', [SingleWallets\ClotPlayController::class, 'bet']);
        Route::post('api/seamless/settlement', [SingleWallets\ClotPlayController::class, 'settle']);
    });

    /** Clot play p2p */
    Route::prefix('clotp2p')->group(function () {
        Route::get('api/seamless/getBalance', [SingleWallets\ClotPlayP2PController::class, 'balance']);
        Route::post('api/seamless/betSettlement', [SingleWallets\ClotPlayP2PController::class, 'betAndSettle']);
        Route::post('api/seamless/cancel', [SingleWallets\ClotPlayP2PController::class, 'cancel']);
        Route::post('api/seamless/bet', [SingleWallets\ClotPlayP2PController::class, 'bet']);
        Route::post('api/seamless/settlement', [SingleWallets\ClotPlayP2PController::class, 'settle']);
    });

    /** Clot play ls */
    Route::prefix('clotls')->group(function () {
        Route::get('api/seamless/getBalance', [SingleWallets\ClotPlayLsController::class, 'balance']);
        Route::post('api/seamless/betSettlement', [SingleWallets\ClotPlayLsController::class, 'betAndSettle']);
        Route::post('api/seamless/cancel', [SingleWallets\ClotPlayLsController::class, 'cancel']);
        Route::post('api/seamless/bet', [SingleWallets\ClotPlayLsController::class, 'bet']);
        Route::post('api/seamless/settlement', [SingleWallets\ClotPlayLsController::class, 'settle']);
    });

    /** Qqpk */
    Route::prefix('qqpk')->group(function () {
        Route::post('balance', [SingleWallets\QqpkController::class, 'balance']);
        Route::post('debit', [SingleWallets\QqpkController::class, 'debit']);
        Route::post('credit', [SingleWallets\QqpkController::class, 'credit']);
    });

    /** impsw */
    Route::post('imperium', [SingleWallets\ImperiumController::class, 'entrance']);

    /** BoleSW APIs */
    Route::prefix('bole')->group(function () {
        Route::post('player/balance', [SingleWallets\BoleController::class, 'balance']);
        Route::post('player/cost', [SingleWallets\BoleController::class, 'cost']);
    });

    /* PGS單錢包API */
    /* Tiger9單錢包API (跟PGS同個api、同個路由) */
    Route::prefix('target')->group(function () {
        /* 取得遊戲錢包金額 */
        Route::post('players/wallet', [SingleWallets\PegasusController::class, 'getBalance']);
        /* 玩家投注 */
        Route::post('bet/placeBet', [SingleWallets\PegasusController::class, 'placeBet']);
        /* 遊戲結算(一般投注) */
        Route::post('bet/settle', [SingleWallets\PegasusController::class, 'settle']);
        /* 遊戲結算(bonus獎勵/免費遊戲) */
        Route::post('bonus/settle', [SingleWallets\PegasusController::class, 'bonus']);
        /* 遊戲結算(無需派彩) [deprecated] */
        Route::post('bet/settle-without-payout', [SingleWallets\PegasusController::class, 'settleWithoutPayout']);
        /* 结算改单 */
        Route::post('bet/settle-change', [SingleWallets\PegasusController::class, 'settleChange']);
        /* 取消投注 */
        Route::post('bet/cancel', [SingleWallets\PegasusController::class, 'cancel']);
        /* 活动奖励派彩 */
        Route::post('transaction/event-bonus', [SingleWallets\PegasusController::class, 'eventBonus']);
    });

    /* BWG單錢包API (跟PGS同個api、不同路由) */
    Route::prefix('bwg')->group(function () {
        /* 取得遊戲錢包金額 */
        Route::post('players/wallet', [SingleWallets\BWGController::class, 'getBalance']);
        /* 玩家投注 */
        Route::post('bet/placeBet', [SingleWallets\BWGController::class, 'placeBet']);
        /* 遊戲結算(一般投注) */
        Route::post('bet/settle', [SingleWallets\BWGController::class, 'settle']);
        /* 遊戲結算(無需派彩) */
        Route::post('bet/settle-without-payout', [SingleWallets\BWGController::class, 'settleWithoutPayout']);
        /* 结算改单 */
        Route::post('bet/settle-change', [SingleWallets\BWGController::class, 'settleChange']);
        /* 遊戲結算(bonus獎勵/免費遊戲) */
        Route::post('bonus/settle', [SingleWallets\BWGController::class, 'bonus']);
        /* 取消投注 */
        Route::post('bet/cancel', [SingleWallets\BWGController::class, 'cancel']);
        /* 活动奖励派彩 */
        Route::post('transaction/event-bonus', [SingleWallets\BWGController::class, 'eventBonus']);
    });

    /** WF APIs */
    Route::prefix('wf')->group(function () {
        Route::post('GetBalance', [SingleWallets\WFController::class, 'getBalance']);
        Route::post('Bet', [SingleWallets\WFController::class, 'bet']);
        Route::post('GameResult', [SingleWallets\WFController::class, 'settle']);
        Route::post('Rollback', [SingleWallets\WFController::class, 'rollback']);
        Route::post('CashBonus', [SingleWallets\WFController::class, 'bonus']);
        Route::post('Jackpot', [SingleWallets\WFController::class, 'jackpot']);
    });

    /* EPIC WIN */
    Route::prefix('epic')->group(function () {
        Route::post('GetBalance', [SingleWallets\EpicWinController::class, 'getBalance']);
        Route::post('Bet', [SingleWallets\EpicWinController::class, 'bet']);
        Route::post('GameResult', [SingleWallets\EpicWinController::class, 'settle']);
        Route::post('Rollback', [SingleWallets\EpicWinController::class, 'rollback']);
        Route::post('CashBonus', [SingleWallets\EpicWinController::class, 'bonus']);
        Route::post('Jackpot', [SingleWallets\EpicWinController::class, 'jackpot']);
    });

    /** WE APIs */
    Route::prefix('we')->group(function () {
        Route::post('validate', [SingleWallets\WEController::class, 'validate']);
        Route::post('balance', [SingleWallets\WEController::class, 'balance']);
        Route::post('debit', [SingleWallets\WEController::class, 'debit']);
        Route::post('credit', [SingleWallets\WEController::class, 'credit']);
        Route::post('rollback', [SingleWallets\WEController::class, 'rollback']);
        Route::post('resettlement', [SingleWallets\WEController::class, 'resettlement']);
        Route::get('genToken', [SingleWallets\WEController::class, 'genToken']);
    });

    /** Spadegaming APIs */
    // digest 驗證在 controller 的 callAction 內（與帳號解析共用同一次查詢），不掛 middleware
    Route::post('spadegaming', [SingleWallets\SpadegamingSWController::class, 'entrance']);

    /** UUSlot APIs */
    Route::prefix('uuslot')->group(function () {
        Route::post('GetBalance', [SingleWallets\UUSlotSWController::class, 'getBalance']);
        Route::post('Bet', [SingleWallets\UUSlotSWController::class, 'bet']);
        Route::post('GameResult', [SingleWallets\UUSlotSWController::class, 'gameResult']);
        Route::post('Rollback', [SingleWallets\UUSlotSWController::class, 'rollback']);
        Route::post('CashBonus', [SingleWallets\UUSlotSWController::class, 'cashBonus']);
        Route::post('Jackpot', [SingleWallets\UUSlotSWController::class, 'jackpot']);
    });

    /** Acewin APIs */
    Route::prefix('acewin')->group(function () {
        /* 驗證遊戲帳號 Session Token */
        Route::post('/auth', [SingleWallets\AcewinController::class, 'auth']);
        /* 投注結算入款 */
        Route::post('/bet', [SingleWallets\AcewinController::class, 'bet']);
        /* 投注結算取消 */
        Route::post('/cancelBet', [SingleWallets\AcewinController::class, 'cancelBet']);
    });

    Route::prefix('astar/gamepool/{gamehall}')->group(function () {
        /* 驗證遊戲帳號 GameToken */
        Route::post('/player/auth', [SingleWallets\AstarController::class, 'authenticate']);
        /* 查餘額 */
        Route::get('/player/balance/{id}', [SingleWallets\AstarController::class, 'balance']);
        /* 登出 */
        Route::post('/player/logout', [SingleWallets\AstarController::class, 'logout']);
        /* 扣款 */
        Route::post('/table/rollout', [SingleWallets\AstarController::class, 'rollout']);
        /* 入款 */
        Route::post('/table/rollin', [SingleWallets\AstarController::class, 'rollin']);
        /* 細單資訊 */
        Route::post('/game/detailtoken', [SingleWallets\AstarController::class, 'detailtoken']);
        /* 查詢未完成注單 */
        Route::get('/game/roundcheck', [SingleWallets\AstarController::class, 'roundcheck']);
        /* 下注取消 */
        Route::post('/game/bet/refund', [SingleWallets\AstarController::class, 'refund']);
    });

    Route::post('rcb988', [SingleWallets\Rcb988Controller::class, 'entrance']);

    Route::prefix('tfgaming')->group(function () {
        Route::get('wallet', [SingleWallets\TFGamingSWController::class, 'wallet']);
        Route::post('transfer', [SingleWallets\TFGamingSWController::class, 'transfer']);
        Route::post('rollback', [SingleWallets\TFGamingSWController::class, 'rollback']);
    });

    Route::prefix('wcasino/api')->group(function () {
        Route::get('balance', [SingleWallets\WcasinoSWController::class, 'balance']);
        Route::post('deduct', [SingleWallets\WcasinoSWController::class, 'bet']);
        Route::post('deposit', [SingleWallets\WcasinoSWController::class, 'settle']);
        Route::post('rollback', [SingleWallets\WcasinoSWController::class, 'rollback']);
    });

    Route::prefix('vpower')->group(function () {
        Route::post('balance', [SingleWallets\VpowerSWController::class, 'balance']);
        Route::post('bet', [SingleWallets\VpowerSWController::class, 'bet']);
        Route::post('settle', [SingleWallets\VpowerSWController::class, 'settle']);
    });

    /** Motivation APIs */
    Route::prefix('motivation')->group(function () {
        Route::get('player/check/{account}', [SingleWallets\MotivationSWController::class, 'checkPlayer']);
        Route::get('transaction/balance/{account}', [SingleWallets\MotivationSWController::class, 'balance']);
        Route::post('transaction/game/bet', [SingleWallets\MotivationSWController::class, 'bet']);
        Route::post('transaction/game/refund', [SingleWallets\MotivationSWController::class, 'refund']);
        Route::post('transaction/game/endround', [SingleWallets\MotivationSWController::class, 'endround']);
        Route::post('transaction/game/rollout', [SingleWallets\MotivationSWController::class, 'bet']);
        Route::post('transaction/game/rollin', [SingleWallets\MotivationSWController::class, 'rollin']);
        Route::post('transaction/game/debit', [SingleWallets\MotivationSWController::class, 'debit']); // 針對『已完成』的訂單做扣款
        Route::post('transaction/game/credit', [SingleWallets\MotivationSWController::class, 'credit']); // 針對『已完成』的訂單做補款
    });

    /**
     * SexySlotCq9 255（CQ9 GameBoy，同 MotivationSW 協議）
     */
    Route::prefix('sexyslotcq9')->group(function () {
        Route::get('player/check/{account}', [SingleWallets\SexySlotCq9SWController::class, 'checkPlayer']);
        Route::get('transaction/balance/{account}', [SingleWallets\SexySlotCq9SWController::class, 'balance']);
        Route::post('transaction/game/bet', [SingleWallets\SexySlotCq9SWController::class, 'bet']);
        Route::post('transaction/game/refund', [SingleWallets\SexySlotCq9SWController::class, 'refund']);
        Route::post('transaction/game/endround', [SingleWallets\SexySlotCq9SWController::class, 'endround']);
        Route::post('transaction/game/rollout', [SingleWallets\SexySlotCq9SWController::class, 'rollout']);
        Route::post('transaction/game/rollin', [SingleWallets\SexySlotCq9SWController::class, 'rollin']);
        Route::post('transaction/game/debit', [SingleWallets\SexySlotCq9SWController::class, 'debit']); // 針對『已完成』的訂單做扣款
        Route::post('transaction/game/credit', [SingleWallets\SexySlotCq9SWController::class, 'credit']); // 針對『已完成』的訂單做補款
        Route::post('transaction/game/takeall', [SingleWallets\SexySlotCq9SWController::class, 'takeall']); // 轉出玩家『全部』額度至遊戲，roundid 與 rollin 共用
        Route::post('transaction/user/payoff', [SingleWallets\SexySlotCq9SWController::class, 'payoff']); // 活動獎勵直接派發給玩家
        // 打錯或未實作的 action：CQ9 規範要回 200 + 1002（動作錯誤），不能讓它掉進 404，必須放在本組最後
        Route::any('{action}', [SingleWallets\SexySlotCq9SWController::class, 'actionError'])->where('action', '.*');
    });

    /* PlayTech */
    Route::prefix('pt')->group(function () {
        // 驗證 token
        Route::post('auth', [SingleWallets\PlayTechController::class, 'auth']);
        Route::post('authenticate', [SingleWallets\PlayTechController::class, 'auth']);
        // 投注 (生成投注紀錄)
        Route::post('bet', [SingleWallets\PlayTechController::class, 'bet']);
        // 派彩 (一場遊戲可有多次派彩)
        Route::post('settlement', [SingleWallets\PlayTechController::class, 'settlement']);
        Route::post('gameRoundResult', [SingleWallets\PlayTechController::class, 'settlement']);
        // 查詢錢包餘額
        Route::post('balance', [SingleWallets\PlayTechController::class, 'balance']);
        Route::post('getBalance', [SingleWallets\PlayTechController::class, 'balance']);
        // 登出 (重置 token)
        Route::post('logout', [SingleWallets\PlayTechController::class, 'logout']);
        // 保持玩家登录会话活动
        Route::post('keepAlive', [SingleWallets\PlayTechController::class, 'keepAlive']);
        // 轉帳到運營商錢包
        Route::post('transfer', [SingleWallets\PlayTechController::class, 'transferFunds']);
        Route::post('transferFunds', [SingleWallets\PlayTechController::class, 'transferFunds']);
        // 此请求用于通知运营商关于奖金实例的事件。当前支持接受（ACCEPTED）和移除（REMOVED）状态。
        Route::post('notifyBonusEvent', [SingleWallets\PlayTechController::class, 'notifyBonusEvent']);
        // 檢查運營商狀態
        $healthCheck = function () {
            return response()->json([
                'code' => 200,
                'message' => 'success',
            ]);
        };
        Route::any('healthCheck', $healthCheck);
        Route::any('healthcheck', $healthCheck);
    });

    Route::prefix('ace333sw')->group(function () {
        Route::post('authenticate', [SingleWallets\Ace333SWController::class, 'authenticate']);
        Route::post('bet', [SingleWallets\Ace333SWController::class, 'bet']);
        Route::post('betresult', [SingleWallets\Ace333SWController::class, 'settle']);
        Route::post('jackpotwin', [SingleWallets\Ace333SWController::class, 'jackpotWin']);
        Route::get('getbalance', [SingleWallets\Ace333SWController::class, 'getBalance']);
        Route::post('refund', [SingleWallets\Ace333SWController::class, 'refund']);
    });

    /**
     * WMLive 完美真人 126
     *
     * @see http://example.invalid:1688/target/tickets/-/issues/2409
     * ! 需要關閉打賞, 請聽方開啟 SendMemberReport 功能
     */
    Route::post('wmlive', SingleWallets\WmLiveSwController::class);

    Route::prefix('onebx/api')->group(function () {
        Route::post('balance', [SingleWallets\OnebxSWController::class, 'balance']);
        Route::post('bet', [SingleWallets\OnebxSWController::class, 'bet']);
        Route::post('rollback', [SingleWallets\OnebxSWController::class, 'rollback']);
        Route::post('undo', [SingleWallets\OnebxSWController::class, 'undo']);
        Route::post('settle', [SingleWallets\OnebxSWController::class, 'settle']);
        Route::post('cancel', [SingleWallets\OnebxSWController::class, 'cancel']);

        Route::post('step/bet', [SingleWallets\OnebxSWController::class, 'stepBet']);
        Route::post('step/rollback', [SingleWallets\OnebxSWController::class, 'stepRollback']);
        Route::post('step/undo', [SingleWallets\OnebxSWController::class, 'stepUndo']);
        Route::post('step/settle', [SingleWallets\OnebxSWController::class, 'stepSettle']);
        Route::post('step/cancel', [SingleWallets\OnebxSWController::class, 'stepCancel']);

        // 尚未實作，掛路由只為了把三方真實 payload 收進 log（404 不會進 Laravel）；一律回非 200，見 controller
        Route::post('step/bonus', [SingleWallets\OnebxSWController::class, 'stepBonus']);
        Route::post('step/refund-bonus', [SingleWallets\OnebxSWController::class, 'stepRefundBonus']);
    });

    /**
     * CR體育 121
     */
    Route::post('cr-sport', SingleWallets\CrSportSwController::class);
    Route::post('cr', SingleWallets\CrSportSwController::class);

    /**
     * Wudi 170
     */
    Route::prefix('wudi')->group(function () {
        Route::post('get_user_balance', [SingleWallets\WudiSWController::class, 'getUserBalance']);
        Route::post('transfer', [SingleWallets\WudiSWController::class, 'transfer']);
    });

    /**
     * FumaSW 171
     */
    Route::post('fuma', [SingleWallets\FumaSWController::class, 'apiSwitch']);

    /**
     * VplusSW 172
     */
    Route::prefix('vplus')->group(function () {
        Route::post('getbalance', [SingleWallets\VplusController::class, 'balance']);
        Route::post('bet', [SingleWallets\VplusController::class, 'bet']);
        Route::post('settlement', [SingleWallets\VplusController::class, 'settle']);
    });

    /**
     * AFB
     */
    Route::post('afb', SingleWallets\AFBSwController::class);

    /**
     * PPSW 83 (Bonn)
     */
    Route::prefix('pp')->as('pp.')->group(function () {
        Route::post('authenticate.html', [SingleWallets\PpswController::class, 'authenticate'])->name('authenticate');
        Route::post('balance.html', [SingleWallets\PpswController::class, 'balance'])->name('balance');
        Route::post('bet.html', [SingleWallets\PpswController::class, 'bet'])->name('bet');
        Route::post('refund.html', [SingleWallets\PpswController::class, 'refund'])->name('refund');
        Route::post('result.html', [SingleWallets\PpswController::class, 'result'])->name('result');
        Route::post('bonusWin.html', [SingleWallets\PpswController::class, 'bonusWin'])->name('bonusWin');
        Route::post('jackpotWin.html', [SingleWallets\PpswController::class, 'jackpotWin'])->name('jackpotWin');
        Route::post('promoWin.html', [SingleWallets\PpswController::class, 'promoWin'])->name('promoWin');
        Route::post('endRound.html', [SingleWallets\PpswController::class, 'endRound'])->name('endRound');

        // Route::post('getBalancePerGame.html', 'SingleWallet\PpswController@getBalancePerGame')->name('getBalancePerGame');
        // Route::post('adjustment.html', 'SingleWallet\PpswController@adjustment')->name('adjustment');
        // Route::post('session/expired', 'SingleWallet\PpswController@sessionExpired')->name('sessionExpired');
    });

    /**
     * EventPPSW 83 (Bonn)
     */
    Route::prefix('refresh_ranking/pp')->as('pp.')->group(function () {
        Route::post('authenticate.html', [SingleWallets\EventPpswController::class, 'authenticate'])->name('authenticate');
        Route::post('balance.html', [SingleWallets\EventPpswController::class, 'balance'])->name('balance');
        Route::post('bet.html', [SingleWallets\EventPpswController::class, 'bet'])->name('bet');
        Route::post('refund.html', [SingleWallets\EventPpswController::class, 'refund'])->name('refund');
        Route::post('result.html', [SingleWallets\EventPpswController::class, 'result'])->name('result');
        Route::post('bonusWin.html', [SingleWallets\EventPpswController::class, 'bonusWin'])->name('bonusWin');
        Route::post('jackpotWin.html', [SingleWallets\EventPpswController::class, 'jackpotWin'])->name('jackpotWin');
        Route::post('promoWin.html', [SingleWallets\EventPpswController::class, 'promoWin'])->name('promoWin');
        Route::post('endRound.html', [SingleWallets\EventPpswController::class, 'endRound'])->name('endRound');

        // Route::post('getBalancePerGame.html', 'SingleWallet\PpswController@getBalancePerGame')->name('getBalancePerGame');
        // Route::post('adjustment.html', 'SingleWallet\PpswController@adjustment')->name('adjustment');
        // Route::post('session/expired', 'SingleWallet\PpswController@sessionExpired')->name('sessionExpired');
    });

    /**
     * Nex4DSW 179 (Bonn)
     */
    Route::prefix('nex4d')->as('nex4d.')->group(function () {
        Route::post('PlayerBalance', [SingleWallets\Nex4DController::class, 'balance'])->name('balance');
        Route::post('PlayerBet', [SingleWallets\Nex4DController::class, 'bet'])->name('bet');
        Route::post('PlayerPayout', [SingleWallets\Nex4DController::class, 'payout'])->name('payout');
        Route::post('PlayerCancelBet', [SingleWallets\Nex4DController::class, 'cancel'])->name('cancel');
        Route::post('PlayerRevertPayout', [SingleWallets\Nex4DController::class, 'revertPayout'])->name('revertPayout');

        /**
         * /external/api/sw/nex4d/getBoUrl
         *
         * 使用方式：
         * 1. 在 .env 中設定 ROUTE_ACCESS_PASSWORD=[REDACTED]
         * 2. 訪問時帶上密碼：
         *    - Header 方式（建議）: 添加 X-Access-Password: [REDACTED]
         *    - 參數方式: ?password=[REDACTED] access log，不建議）
         */
        Route::middleware(PasswordProtected::class)->group(function () {
            Route::get('getBoUrl', function () {
                $vendor = Vendor::with(['vendorInfos'])->find(179);
                $vendorInfo = $vendor->vendorInfos->firstWhere('status', 1);

                if (!$vendorInfo) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No active VendorInfo found for vendor_id 179',
                    ], 404);
                }

                $vendorService = App::make(VendorService::class);
                $vendorDriver = $vendorService->getDriver(179);
                $vendorDriver->setVendorInfo($vendorInfo);
                $url = $vendorDriver->getBoUrl();

                return response()->json([
                    'success' => true,
                    'message' => 'Access granted to protected route',
                    'bo_url' => $url,
                ]);
            });
        });
    });

    /**
     * TTHSW 178
     */
    Route::prefix('tthsw')->as('tthsw.')->group(function () {
        Route::post('auth', [SingleWallets\TTHSWController::class, 'auth'])->name('auth');
        Route::post('balance', [SingleWallets\TTHSWController::class, 'balance'])->name('balance');
        Route::post('deduct', [SingleWallets\TTHSWController::class, 'deduct'])->name('deduct');
        Route::post('win', [SingleWallets\TTHSWController::class, 'win'])->name('win');
        Route::post('refund', [SingleWallets\TTHSWController::class, 'refund'])->name('refund');
        Route::post('complete', [SingleWallets\TTHSWController::class, 'complete'])->name('complete');
        Route::post('refreshtoken', [SingleWallets\TTHSWController::class, 'refreshToken'])->name('refreshtoken');
        Route::post('promowin', [SingleWallets\TTHSWController::class, 'promoWin'])->name('promowin');
        Route::post('wallet', [SingleWallets\TTHSWController::class, 'wallet'])->name('wallet');
    });

    /**
     * Top55SW 182
     */
    Route::prefix('top55')->group(function () {
        Route::post('api/balance', [SingleWallets\Top55Controller::class, 'balance']);
        Route::post('api/transaction', [SingleWallets\Top55Controller::class, 'transaction']);
        Route::post('api/batch-transactions', [SingleWallets\Top55Controller::class, 'batchTransactions']);
    });

    /**
     * BeSoft 224
     */
    Route::post('besoft', [SingleWallets\BeSoftSWController::class, 'entrance']);

    /**
     * KissH5SW 233
     */
    Route::prefix('kiss/h5')->group(function () {
        Route::post('GetBalance', [SingleWallets\KissH5Controller::class, 'getBalance']);
        Route::post('Bet', [SingleWallets\KissH5Controller::class, 'bet']);
        Route::post('GameResult', [SingleWallets\KissH5Controller::class, 'settle']);
        Route::post('Rollback', [SingleWallets\KissH5Controller::class, 'rollback']);
        Route::post('CashBonus', [SingleWallets\KissH5Controller::class, 'bonus']);
        Route::post('Jackpot', [SingleWallets\KissH5Controller::class, 'jackpot']);
        Route::post('BetNSettle', [SingleWallets\KissH5Controller::class, 'betNSettle']);
        Route::post('CancelBetNSettle', [SingleWallets\KissH5Controller::class, 'cancelBetNSettle']);
    });

    /**
     * MetaGaming SW 252
     */
    Route::prefix('metagaming')->group(function () {
        Route::post('GetBalance', [SingleWallets\MetaGamingSWController::class, 'getBalance']);
        Route::post('Bet', [SingleWallets\MetaGamingSWController::class, 'bet']);
        Route::post('GameResult', [SingleWallets\MetaGamingSWController::class, 'settle']);
        Route::post('Rollback', [SingleWallets\MetaGamingSWController::class, 'rollback']);
        Route::post('CashBonus', [SingleWallets\MetaGamingSWController::class, 'bonus']);
        Route::post('Jackpot', [SingleWallets\MetaGamingSWController::class, 'jackpot']);
        Route::post('BetNSettle', [SingleWallets\MetaGamingSWController::class, 'betNSettle']);
        Route::post('CancelBetNSettle', [SingleWallets\MetaGamingSWController::class, 'cancelBetNSettle']);
    });

    /**
     * Slot4D SW 254
     */
    Route::prefix('slot4dsw')->group(function () {
        Route::post('GetBalance', [SingleWallets\Slot4DSWController::class, 'getBalance']);
        Route::post('Bet', [SingleWallets\Slot4DSWController::class, 'bet']);
        Route::post('GameResult', [SingleWallets\Slot4DSWController::class, 'settle']);
        Route::post('Rollback', [SingleWallets\Slot4DSWController::class, 'rollback']);
        Route::post('CashBonus', [SingleWallets\Slot4DSWController::class, 'bonus']);
        Route::post('Jackpot', [SingleWallets\Slot4DSWController::class, 'jackpot']);
        Route::post('BetNSettle', [SingleWallets\Slot4DSWController::class, 'betNSettle']);
        Route::post('CancelBetNSettle', [SingleWallets\Slot4DSWController::class, 'cancelBetNSettle']);
    });

    /**
     * Rectangle SW 234
     */
    Route::prefix('rectangle')->group(function () {
        Route::post('api/v1/authenticate', [SingleWallets\RectangleSwController::class, 'authenticate']);
        Route::get('api/v1/wallets', [SingleWallets\RectangleSwController::class, 'wallets']);
        Route::post('api/v1/transactions', [SingleWallets\RectangleSwController::class, 'transactions']);
    });

    /**
     * Joker Baccarat SW 235
     */
    Route::prefix('jokerbaccarat')->group(function () {
        Route::post('balance/detail', [SingleWallets\JokerBaccaratSwController::class, 'balance']);
        Route::post('balance/transfer', [SingleWallets\JokerBaccaratSwController::class, 'transfer']);
        Route::post('goodRoad', [SingleWallets\JokerBaccaratSwController::class, 'goodRoad']);
    });

    /**
     * Pxplay SW 236
     */
    Route::prefix('pxplay')->group(function () {
        Route::post('api/v1/authenticate', [SingleWallets\PxplaySwController::class, 'authenticate']);
        Route::get('api/v1/wallets', [SingleWallets\PxplaySwController::class, 'wallets']);
        Route::post('api/v1/transactions', [SingleWallets\PxplaySwController::class, 'transactions']);
    });

    /**
     * CosmoPlay SW 249
     */
    Route::prefix('cosmoplay')->group(function () {
        Route::post('auth-check', [SingleWallets\CosmoPlaySWController::class, 'authCheck']);
        Route::post('bet-result', [SingleWallets\CosmoPlaySWController::class, 'betResult']);
        Route::post('win-result', [SingleWallets\CosmoPlaySWController::class, 'winResult']);
        Route::post('cancel-result', [SingleWallets\CosmoPlaySWController::class, 'cancelResult']);
    });

    /**
     * GM SW 237
     */
    Route::prefix('gm')->group(function () {
        Route::post('api/luck/user/verify_session', [SingleWallets\GMSWController::class, 'verifySession']);
        Route::post('api/luck/balance/get_balance', [SingleWallets\GMSWController::class, 'getBalance']);
        Route::post('api/luck/balance/change_balance', [SingleWallets\GMSWController::class, 'changeBalanceCallback']);
    });

    /**
     * Sexy Slot SW 248（Tiger Games Seamless Wallet）
     */
    Route::prefix('sexyslotsw')->group(function () {
        Route::post('player', [SingleWallets\SexySlotSWController::class, 'player']);
        Route::post('fund', [SingleWallets\SexySlotSWController::class, 'fund']);
        Route::post('inquiry', [SingleWallets\SexySlotSWController::class, 'inquiry']);
    });

    /**
     * RG SW 250
     */
    Route::prefix('rg')->group(function () {
        Route::get('user/check/{username}', [SingleWallets\RgswController::class, 'checkPlayer']);
        Route::get('user/balance/{username}', [SingleWallets\RgswController::class, 'balance']);
        Route::post('game/bet', [SingleWallets\RgswController::class, 'bet']);
        Route::post('game/payout', [SingleWallets\RgswController::class, 'payout']);
        Route::post('game/endRound', [SingleWallets\RgswController::class, 'endRound']);
        Route::post('game/betPayout', [SingleWallets\RgswController::class, 'betPayout']);
        Route::post('game/refund', [SingleWallets\RgswController::class, 'refund']);
        Route::post('game/debit', [SingleWallets\RgswController::class, 'debit']);
        Route::post('game/credit', [SingleWallets\RgswController::class, 'credit']);
        Route::post('game/sumBet', [SingleWallets\RgswController::class, 'sumBet']);
    });

    /**
     * MegaH5SW 119
     */
    Route::prefix('megah5')->group(function () {
        Route::post('GetBalance', [SingleWallets\MegaH5Controller::class, 'getBalance']);
        Route::post('Bet', [SingleWallets\MegaH5Controller::class, 'bet']);
        Route::post('GameResult', [SingleWallets\MegaH5Controller::class, 'settle']);
        Route::post('Rollback', [SingleWallets\MegaH5Controller::class, 'rollback']);
        Route::post('CashBonus', [SingleWallets\MegaH5Controller::class, 'bonus']);
        Route::post('Jackpot', [SingleWallets\MegaH5Controller::class, 'jackpot']);
        Route::post('BetNSettle', [SingleWallets\MegaH5Controller::class, 'betNSettle']);
        Route::post('CancelBetNSettle', [SingleWallets\MegaH5Controller::class, 'cancelBetNSettle']);
    });

    /**
     * DstPlaySW 256
     */
    Route::prefix('dstplaysw')->group(function () {
        Route::post('v1/getAuth', [SingleWallets\DstPlaySWController::class, 'getAuth']);
        Route::post('v1/getBalance', [SingleWallets\DstPlaySWController::class, 'getBalance']);
        Route::post('v1/gameResult', [SingleWallets\DstPlaySWController::class, 'gameResult']);
        Route::post('v1/bonusResult', [SingleWallets\DstPlaySWController::class, 'bonusResult']);
        Route::post('v1/promoResult', [SingleWallets\DstPlaySWController::class, 'promoResult']);
        Route::post('v1/refund', [SingleWallets\DstPlaySWController::class, 'refund']);
    });

    /**
     * AiGamingSW 260
     */
    Route::prefix('aigaming')->group(function () {
        Route::post('seamless/getBalance', [SingleWallets\AiGamingSWController::class, 'getBalance']);
        Route::post('seamless/deduct', [SingleWallets\AiGamingSWController::class, 'deduct']);
        Route::post('seamless/settle', [SingleWallets\AiGamingSWController::class, 'settle']);
        Route::post('seamless/bonus', [SingleWallets\AiGamingSWController::class, 'bonus']);
        Route::post('seamless/rollback', [SingleWallets\AiGamingSWController::class, 'rollback']);
        Route::post('seamless/cashBonus', [SingleWallets\AiGamingSWController::class, 'cashBonus']);
    });

});
