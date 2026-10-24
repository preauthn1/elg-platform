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
    FrontendMiddlewares\AuthMember::class,
    FrontendMiddlewares\CheckAgentMaintain::class,
    FrontendMiddlewares\MergeUserData::class,
    FrontendMiddlewares\TrackMemberActivity::class,
    RequestLogger::class,
];

Route::middleware($middlewares)->group(function () {
    Route::prefix('member')->group(function () {
        /** 登出 */
        Route::post('logout', [FrontendControllers\MemberController::class, 'logout']);
        /** 會員詳情 */
        Route::post('detail', [FrontendControllers\MemberController::class, 'detail']);
        /** 檢查會員資料完整性 */
        Route::post('check-profile', [FrontendControllers\MemberController::class, 'checkProfile']);
        /** 修改會員資料 */
        Route::post('update-profile', [FrontendControllers\MemberController::class, 'updateProfile']);
        /** 修改會員密碼 */
        Route::post('change-password', [FrontendControllers\MemberController::class, 'changePassword']);
        /** 修改支付密碼 */
        Route::post('change-payment-password', [FrontendControllers\MemberController::class, 'changePaymentPassword']);
        /** 設定真實姓名 (firstname) */
        Route::post('set-realname', [FrontendControllers\MemberController::class, 'setRealName']);
        /** 設定生日 */
        Route::post('set-birthday', [FrontendControllers\MemberController::class, 'setBirthday']);

        /** 發送驗證碼，需傳入驗證過的電子信箱或手機號碼 sendValidateCode */
        Route::post('send-validation-code', [FrontendControllers\ValidationCodeController::class, 'sendCodeToMember']);

        /** 滑塊驗證出題（4188）*/
        Route::post('captcha', [FrontendControllers\CaptchaController::class, 'create']);
        /** 滑塊驗證判定（4188），通過取得 captcha_token */
        Route::post('captcha/verify', [FrontendControllers\CaptchaController::class, 'verify']);
        /** 檢核驗證碼 checkValidateCode */
        // Route::post('check-validation-code', [FrontendControllers\ValidationCodeController::class, 'checkCode']);
        /** 修改手機號 (重新綁定) resetBinding */
        Route::post('reset-mobile', [FrontendControllers\ValidationCodeController::class, 'resetMobile']);
        /** 修改Email (重新綁定) resetBinding */
        Route::post('reset-email', [FrontendControllers\ValidationCodeController::class, 'resetEmail']);

        /** 取得有綁定帳號的遊戲商列表 */
        Route::post('vendors', [FrontendControllers\MemberController::class, 'vendors']);
        /** 會員輸贏報表 */
        Route::post('winloses', [FrontendControllers\MemberController::class, 'winloses']);
        /** 會員所有遊戲商的有效投注額加總 */
        Route::post('total-valid-bets', [FrontendControllers\MemberController::class, 'totalValidBets']);
        /** 取得追蹤事件 */
        Route::post('tracking-events', [FrontendControllers\MemberController::class, 'trackingEvents']);

        /**
         * 查詢上一次轉入的遊戲商, 查詢餘額然後轉回本地主錢包 transferFromLastLog
         * 1. 訪問首頁或我的將餘額轉回本地
         * 2. 進入遊戲, 將餘額轉回本地, 再帶入遊戲
         */
        Route::post('transfer', [FrontendControllers\MemberTransferController::class, 'transfer']);

        /** 漸進式轉回本地錢包 */
        Route::post('transfer-all', [FrontendControllers\MemberTransferController::class, 'transferAll']);

        /** 轉回近三個月有轉入紀錄的遊戲商餘額 (V1活動用) */
        Route::post('transfer-back-recent', [FrontendControllers\MemberTransferController::class, 'transferBackRecent']);

        /** 查詢遊戲商餘額 */
        Route::post('vendor-balance', [FrontendControllers\MemberTransferController::class, 'getVendorBalance']);

        /** 查詢轉帳狀態 (測試用) */
        Route::post('check-transfer-status', [FrontendControllers\MemberTransferController::class, 'checkTransferStatus']);
    });

    /** 老虎機爭霸賽沿用舊版 */
    Route::prefix('event/slot-competition')->group(function () {
        /** 期數列表 event/slot_competition/list */
        Route::post('list', [FrontendControllers\SlotCompetitionController::class, 'list']);
        /** 排行榜 event/slot_competition/leader_board */
        Route::post('leader-board', [FrontendControllers\SlotCompetitionController::class, 'leaderBoard']);
        /** 我的排名 event/slot_competition/ranking */
        Route::post('ranking', [FrontendControllers\SlotCompetitionController::class, 'ranking']);
        /** 獎項列表 event/slot_competition/awards */
        Route::post('awards', [FrontendControllers\SlotCompetitionController::class, 'awards']);
        /** 領取獎項 event/slot_competition/receive */
        Route::post('receive', [FrontendControllers\SlotCompetitionController::class, 'receive']);
    });

    /** 積寶活動沿用舊版 */
    Route::prefix('event/jackpot')->group(function () {
        /** 活動資訊與自己的獎項 event/jackpot/show */
        Route::post('show', [FrontendControllers\EventJackpotController::class, 'show']);
        /** 中獎名單 event/jackpot/win_list */
        Route::post('win-list', [FrontendControllers\EventJackpotController::class, 'winList']);
        /** 領獎 event/jackpot/receive */
        Route::post('receive', [FrontendControllers\EventJackpotController::class, 'receive']);
        /** 取得通知 event/jackpot/check_notify */
        Route::post('check-notify', [FrontendControllers\EventJackpotController::class, 'checkNotify']);
        /** 確認通知 event/jackpot/confirm_notify */
        Route::post('confirm-notify', [FrontendControllers\EventJackpotController::class, 'confirmNotify']);
    });

    /** 刷分排行榜沿用舊版（tpower 獨有，見遷移文件 4-1／4-2） */
    Route::prefix('event/refresh-ranking')->group(function () {
        /** 期數列表 event/refresh_ranking/list */
        Route::post('list', [FrontendControllers\RefreshRankingController::class, 'list']);
        /** 排行榜 event/refresh_ranking/leader_board */
        Route::post('leader-board', [FrontendControllers\RefreshRankingController::class, 'leaderBoard']);
        /** 錢包餘額 event/refresh_ranking/wallet_balance */
        Route::post('wallet-balance', [FrontendControllers\RefreshRankingController::class, 'walletBalance']);
        /** 遊戲商列表 event/refresh_ranking/vendor */
        Route::post('vendor', [FrontendControllers\RefreshRankingController::class, 'vendor']);
        /** 遊戲列表 event/refresh_ranking/game */
        Route::post('game', [FrontendControllers\RefreshRankingController::class, 'game']);
        /** 獎項列表 event/refresh_ranking/awards */
        Route::post('awards', [FrontendControllers\RefreshRankingController::class, 'awards']);
        /** 依遊戲商啟動遊戲 event/refresh_ranking/game_url */
        Route::post('game-url', [FrontendControllers\RefreshRankingController::class, 'gameUrl']);
    });

    /** 活動獎項沿用舊版 */
    Route::prefix('prize')->group(function () {
        /** 已領取的獎項紀錄 prizes */
        Route::post('list', [FrontendControllers\PrizeController::class, 'list']);
        /** 依活動明細抽出並領取獎項 getPrizeByDetailId */
        Route::post('draw-by-detail', [FrontendControllers\PrizeController::class, 'drawByDetail']);
        /** 玩家自主取消未領取的儲值類活動獎勵 cancelPrize */
        Route::post('cancel', [FrontendControllers\PrizeController::class, 'cancel']);
        /** 一次領取該活動所有未兌換的獎勵 getAllPrize */
        Route::post('draw-all', [FrontendControllers\PrizeController::class, 'drawAll']);
        /** 抽出並領取活動獎項 getPrize */
        Route::post('draw', [FrontendControllers\PrizeController::class, 'draw']);
        /** 玩家自選獎項後領取 choosePrize */
        Route::post('choose', [FrontendControllers\PrizeController::class, 'choose']);
        /** 設定獎項為已領取（舊版自 2019-11-25 起即為空殼，保留相容） setPrize */
        Route::post('set', [FrontendControllers\PrizeController::class, 'setPrize']);
    });

    /** 活動系統沿用舊版 */
    Route::prefix('event')->group(function () {
        /** 舊版活動列表 events */
        Route::post('legacy-list', [FrontendControllers\EventController::class, 'list']);
        /** 舊版活動詳情 event */
        Route::post('legacy-detail', [FrontendControllers\EventController::class, 'view']);
        /** 舊版活動資格驗證（action 60 邀請玩家活動） event/validate */
        Route::post('legacy-validate', [FrontendControllers\EventController::class, 'validateEvent']);
        /** 新玩家邀請活動 event/getNewInviteEvent */
        Route::post('new-invite', [FrontendControllers\EventController::class, 'newInviteEvent']);
        /** 領取新玩家邀請活動獎勵 event/getNewInvitePrize */
        Route::post('new-invite/receive', [FrontendControllers\EventController::class, 'newInvitePrize']);
        /** 檢查是否有未領取獎勵 event/checkPrize */
        Route::post('remind', [FrontendControllers\EventController::class, 'remind']);
        /** 主頁驚嘆號提醒：VIP 達標可領獎、活動 V1 未領取獎勵、活動 V2 可參加但尚未參加 */
        Route::post('prize-remind', [FrontendControllers\EventController::class, 'prizeRemind']);
        /** v1 + v2 領獎紀錄 */
        Route::post('claim-records', [FrontendControllers\EventV2Controller::class, 'claimRecords']);
        /** 依遊戲商列出目前進行中、可參與的活動與遊戲 event/vendorGames */
        Route::post('vendor-games', [FrontendControllers\EventController::class, 'vendorGames']);

        /** 抽獎券活動相關 */
        Route::prefix('lucky-draw')->group(function () {
            /** 抽獎活動期數列表 event/lucky-draw/issues */
            Route::post('issues', [FrontendControllers\EventLuckyDrawController::class, 'issueList']);
            /** 抽獎活動明細 event/lucky-draw/issues/detail */
            Route::post('issues/detail', [FrontendControllers\EventLuckyDrawController::class, 'issueDetail']);
            /** 每期抽獎券明細 event/lucky-draw/issues/tickets */
            Route::post('issues/tickets', [FrontendControllers\EventLuckyDrawController::class, 'issueTickets']);
            /** 領取獎項 event/lucky-draw/issues/receive */
            Route::post('issues/receive', [FrontendControllers\EventLuckyDrawController::class, 'receive']);
            /** 該期獎項全部領取 event/lucky-draw/issues/receive-all */
            Route::post('issues/receive-all', [FrontendControllers\EventLuckyDrawController::class, 'receiveAll']);
        });

        /** W-Jackpot 抽獎券活動相關 */
        Route::prefix('wjackpot')->group(function () {
            /** 期數列表 event/wjackpot/issues */
            Route::post('issues', [FrontendControllers\EventWJackpotController::class, 'issueList']);
            /** 期數明細 event/wjackpot/issues/detail */
            Route::post('issues/detail', [FrontendControllers\EventWJackpotController::class, 'issueDetail']);
            /** 每期抽獎券明細 event/wjackpot/issues/tickets */
            Route::post('issues/tickets', [FrontendControllers\EventWJackpotController::class, 'issueTickets']);
            /** 領取獎項 event/wjackpot/issues/receive */
            Route::post('issues/receive', [FrontendControllers\EventWJackpotController::class, 'receive']);
            /** 該期獎項全部領取 event/wjackpot/issues/receive-all */
            Route::post('issues/receive-all', [FrontendControllers\EventWJackpotController::class, 'receiveAll']);
        });

        /** W-Jackpot 6D 抽獎券活動相關 */
        Route::prefix('wjackpot6d')->group(function () {
            /** 期數列表 event/wjackpot6d/issues */
            Route::post('issues', [FrontendControllers\EventWJackpotController::class, 'issueList6d']);
            /** 期數明細 event/wjackpot6d/issues/detail */
            Route::post('issues/detail', [FrontendControllers\EventWJackpotController::class, 'issueDetail6d']);
            /** 每期抽獎券明細 event/wjackpot6d/issues/tickets */
            Route::post('issues/tickets', [FrontendControllers\EventWJackpotController::class, 'issueTickets6d']);
            /** 領取獎項 event/wjackpot6d/issues/receive */
            Route::post('issues/receive', [FrontendControllers\EventWJackpotController::class, 'receive6d']);
            /** 該期獎項全部領取 event/wjackpot6d/issues/receive-all */
            Route::post('issues/receive-all', [FrontendControllers\EventWJackpotController::class, 'receive6dAll']);
        });

        /** 成長彩金計劃（漸進式彩金活動） */
        Route::prefix('progressive-jackpot')->group(function () {
            /** 查詢目前檔位/解鎖進度 event/progressive-jackpot/list */
            Route::post('list', [FrontendControllers\EventProgressiveJackpotController::class, 'list']);
            /** 領取已解鎖彩金 event/progressive-jackpot/receive */
            Route::post('receive', [FrontendControllers\EventProgressiveJackpotController::class, 'receive']);
        });
    });

    /** 活動系統 V2 */
    Route::prefix('event-v2')->group(function () {
        Route::post('detail', [FrontendControllers\EventV2Controller::class, 'detail']);
        Route::post('join', [FrontendControllers\EventV2Controller::class, 'join']);
        Route::post('withdraw', [FrontendControllers\EventV2Controller::class, 'withdraw']);
        Route::post('quit', [FrontendControllers\EventV2Controller::class, 'quit']);
        Route::post('join-detail', [FrontendControllers\EventV2Controller::class, 'joinDetail']);
        // 紅包畫面資訊（target/tickets#4234）：次數／金額／積分／時段；「抽」仍走上面的 join
        Route::post('angpow/info', [FrontendControllers\EventV2AngpowController::class, 'info']);
    });

    /** 新人專享挑戰活動 */
    Route::prefix('newbie-challenge')->group(function () {
        /** 參加頁的活動介紹內容 */
        Route::post('detail', [FrontendControllers\NewbieChallengeController::class, 'detail']);
        /** 領取活動金 */
        Route::post('join', [FrontendControllers\NewbieChallengeController::class, 'join']);
        /** 查詢活動進度 */
        Route::post('progress', [FrontendControllers\NewbieChallengeController::class, 'progress']);
        /** 挑戰流水達標後，進入下一階段 */
        Route::post('unlock-bonus', [FrontendControllers\NewbieChallengeController::class, 'unlockBonus']);
        /** 待領取彩金階段，會員按「下一步」主動確認已儲值：查訂單表核銷彩金並進入彩金任務中 */
        Route::post('confirm-deposit', [FrontendControllers\NewbieChallengeController::class, 'confirmDeposit']);
        /** 彩金流水達標後，撥款進入下一階段 */
        Route::post('claim-bonus', [FrontendControllers\NewbieChallengeController::class, 'claimBonus']);
        /** 主動放棄活動 */
        Route::post('quit', [FrontendControllers\NewbieChallengeController::class, 'quit']);
    });

    /** 稽核流水 audit-bet-amount */
    Route::prefix('audit')->group(function () {
        /** 申請清除流水 relieveEventMode */
        Route::post('release-application', [FrontendControllers\AuditController::class, 'releaseApplication']);
    });

    /** 洗分／取款 */
    Route::prefix('withdrawal')->group(function () {
        /** 洗分紀錄 withdrawal/orders */
        Route::post('orders', [FrontendControllers\WithdrawalController::class, 'getOrders']);
        /** 稽核流水條件 (取得取款限制條件) flowLimit/show */
        Route::post('flow-limit', [FrontendControllers\WithdrawalController::class, 'getFlowLimit']);
        /** 檢查是否有綁定銀行帳號 withdrawal/check */
        // todo 應拆分兩個接口 1. 檢查是否可以提款 2. 取得用戶綁定銀行帳號列表
        Route::post('check-bank-account', [FrontendControllers\WithdrawalController::class, 'checkBankAccount']);
        /** 洗分銀行列表 withdrawal/account */
        Route::post('banks', [FrontendControllers\WithdrawalController::class, 'getBanks']);
        /** 核對會員支付密碼 checkPaymentPassword */
        // todo 可繞過有資安風險
        Route::post('confirm-payment-password', [FrontendControllers\WithdrawalController::class, 'confirmPaymentPassword']);
        /** 建立提款訂單 withdrawal */
        Route::post('create-order', [FrontendControllers\WithdrawalController::class, 'createOrder']);
        /** 綁定銀行帳號 withdrawal/account */
        Route::post('bind-bank-account', [FrontendControllers\WithdrawalController::class, 'bindBankAccount']);
    });

    /** 上分/充值  */
    Route::prefix('deposit')->group(function () {
        /** ! VPlus33 充值分組整合渠道取代原本兩隻接口 */
        Route::post('types', [FrontendControllers\DepositController::class, 'depositTypesGroup']);
        /** 快速充值 (獲取所有支付方式的列表) deposit/getOrderType */
        Route::post('top-up-types', [FrontendControllers\DepositController::class, 'topUpTypes']);
        /** 快速充值渠道 deposit/getChildOrderType */
        Route::post('top-up-channels', [FrontendControllers\DepositController::class, 'topUpChannels']);
        /** 充值訂單列表 orders */
        Route::post('orders', [FrontendControllers\DepositController::class, 'orders']);
        /** 提交充值訂單 deposit/order */
        Route::post('create-order', [FrontendControllers\DepositController::class, 'createOrder']);
        /** 固定收款地址（永久地址模式）：會員點選 BIND_CRYPTO_WALLET 的支付通道時取得 */
        Route::post('crypto-address', [FrontendControllers\DepositController::class, 'cryptoAddress']);
    });

    /** 交易紀錄 */
    Route::prefix('transaction')->group(function () {
        /** 交易紀錄 transaction/getTransactionList */
        Route::post('logs', [FrontendControllers\TransactionLogController::class, 'searchTransactionLogs']);
        /** 紅包記錄列表 transaction/redEnvelope */
        Route::post('red-envelope', [FrontendControllers\TransactionLogController::class, 'redEnvelopeLogs']);
        /** 紅包記錄列表 transaction/redEnvelope */
        Route::post('customer-service', [FrontendControllers\TransactionLogController::class, 'customerServiceLogs']);
        /** 轉點記錄列表 transactions (遊戲轉入轉出的交易紀錄) */
        Route::post('game-transfer-logs', [FrontendControllers\TransactionLogController::class, 'gameTransferLogs']);
    });

    /** 錢包交易紀錄 (VW 專屬) */
    Route::prefix('wallet')->group(function () {
        /** 主錢包紀錄 mainWalletLog */
        Route::post('main-logs', [FrontendControllers\WalletController::class, 'mainWalletLogs']);
        /** 遊戲錢包紀錄 gameWalletLog */
        Route::post('game-logs', [FrontendControllers\WalletController::class, 'gameWalletLogs']);
        /** (新) 錢包紀錄, 透過 wallet_type 切換主錢包或遊戲錢包紀錄 */
        Route::post('histories', [FrontendControllers\WalletController::class, 'walletHistories']);

        /** 主錢包轉帳至第二錢包 transferGameWallet */
        Route::post('transfer', [FrontendControllers\WalletController::class, 'walletTransfer']);
    });

    /** 排行榜 */
    Route::prefix('ranking')->group(function () {
        /** 今日遊戲赢家榜 game_winner_list */
        Route::post('game-winners', [FrontendControllers\RankingController::class, 'gameWinners']);
        /** 今日盈利榜 profit_list */
        Route::post('profits', [FrontendControllers\RankingController::class, 'profits']);
        /** 中獎排行榜 ranking/winning */
        Route::post('winning', [FrontendControllers\RankingController::class, 'winning']);
        /** 平台投注排行榜 ranking/platform_betting */
        Route::post('platform-betting', [FrontendControllers\RankingController::class, 'platformBetting']);
    });

    /** 三層代 */
    Route::prefix('three-hierarchy')->group(function () {
        /** 主資料 */
        Route::post('', [FrontendControllers\ThreeHierarchyController::class, 'index']);
        /** 我的業績 */
        Route::post('achievements', [FrontendControllers\ThreeHierarchyController::class, 'achievements']);
        /** 我的返利 */
        Route::post('rebates', [FrontendControllers\ThreeHierarchyController::class, 'rebates']);
        /** 三層代規則說明 */
        Route::post('rule', [FrontendControllers\ThreeHierarchyController::class, 'rule']);

        Route::prefix('withdrawals')->group(function () {
            /** 提現總覽 */
            Route::post('', [FrontendControllers\ThreeHierarchyController::class, 'withdrawals']);
            /** 申請提現 */
            Route::post('create', [FrontendControllers\ThreeHierarchyController::class, 'withdrawalCreate']);
            /** 提現紀錄 */
            Route::post('histories', [FrontendControllers\ThreeHierarchyController::class, 'withdrawalHistories']);
        });
    });

    Route::prefix('platform-announcement')->group(function () {
        /** 平台公告列表 */
        Route::post('list', [FrontendControllers\PlatformAnnouncementController::class, 'list']);
        /** 平台公告未讀數量 */
        Route::post('unread', [FrontendControllers\PlatformAnnouncementController::class, 'unread']);
        /** 平台公告已讀 */
        Route::post('read-all', [FrontendControllers\PlatformAnnouncementController::class, 'readAll']);
    });

    /** 站內信（鈴鐺第三分頁） 工單4152 */
    Route::prefix('member-mail')->group(function () {
        /** 站內信列表（多語系取當前語系，未填 fallback 英文） */
        Route::post('list', [FrontendControllers\MemberMailController::class, 'list']);
        /** 站內信未讀數（供鈴鐺加總） */
        Route::post('unread', [FrontendControllers\MemberMailController::class, 'unread']);
        /** 標記單封已讀（逐封記錄） */
        Route::post('read', [FrontendControllers\MemberMailController::class, 'read']);
        /** 全部標記已讀（進入站內信頁時呼叫，與系統通知一致） */
        Route::post('read-all', [FrontendControllers\MemberMailController::class, 'readAll']);
    });

    /** 系統通知 */
    Route::prefix('system-notify')->group(function () {
        /** 系統通知列表 systemNotification/list */
        Route::post('list', [FrontendControllers\SystemNotifyController::class, 'list']);
        /** 系統通知詳情 systemNotification/getDetail */
        Route::post('detail', [FrontendControllers\SystemNotifyController::class, 'detail']);
        /** 系統通知未讀數量 systemNotification/unread */
        Route::post('unread', [FrontendControllers\SystemNotifyController::class, 'unread']);
        /** 系統通知已讀 systemNotification/readAll */
        Route::post('read-all', [FrontendControllers\SystemNotifyController::class, 'readAll']);
    });

    Route::prefix('coupon')->group(function () {
        /** 優惠券列表 */
        Route::post('redeem', [FrontendControllers\CouponController::class, 'redeem']);
    });

    Route::prefix('realname-validation')->group(function () {
        /** 真實姓名 */
        Route::post('', [FrontendControllers\RealnameValidationController::class, 'set']);
        /** 檢查真實姓名 */
        Route::post('check', [FrontendControllers\RealnameValidationController::class, 'check']);
    });

    Route::prefix('vip')->group(function () {
        /** 會員VIP資訊 */
        Route::post('info', [FrontendControllers\VipController::class, 'info']);
        /** 會員VIP等級列表 */
        Route::post('contents', [FrontendControllers\VipController::class, 'contents']);
        /** 領獎紀錄 */
        Route::post('reward-history', [FrontendControllers\VipController::class, 'rewardHistory']);
        /** VIP 稽核流水限制列表 vip/flow_limit */
        Route::post('flow-limit', [FrontendControllers\VipController::class, 'flowLimit']);
        /** 領取單筆 VIP 獎勵 vip/receive */
        Route::post('receive', [FrontendControllers\VipController::class, 'receive']);
        /** 一次領取所有待領的每日返水獎勵 vip/receive_rebate */
        Route::post('receive-rebate', [FrontendControllers\VipController::class, 'receiveRebate']);
    });

    Route::prefix('game')->group(function () {
        /** 遊戲列表 myGames */
        Route::post('list', [FrontendControllers\GameController::class, 'list']);
        /** 按遊戲類型獲取遊戲列表 */
        Route::post('list-by-types', [FrontendControllers\GameController::class, 'listByTypes']);
        /** 遊戲詳情 api/game */
        Route::post('show', [FrontendControllers\GameController::class, 'show']);
        /** 遊戲連結 getGameUrl */
        Route::post('url', [FrontendControllers\GameController::class, 'getUrl']);
        /** 嵌入遊戲連結 getWidgetUrl */
        Route::post('widget-url', [FrontendControllers\GameController::class, 'getWidgetUrl']);
        /** 取得遊戲商提供的優惠碼 getBonusCode */
        // 暫時不支援前端呼叫
        // Route::post('bonus-code', [FrontendControllers\GameController::class, 'getBonusCode']);
        /** 加入/取消我的最愛遊戲 favouriteGame */
        Route::post('set-favourite', [FrontendControllers\GameController::class, 'setFavourite']);
        /** 最近遊戲ID列表 recentlyGameIds */
        Route::post('recently-game-ids', [FrontendControllers\GameController::class, 'recentlyGameIds']);
        /** 優惠遊戲 getPromotionGames */
        Route::post('promotion-games', [FrontendControllers\GameController::class, 'promotionGames']);
    });

    Route::prefix('vendor')->group(function () {
        /** 修改遊戲商帳號密碼 resetVendorPassword */
        Route::post('reset-password', [FrontendControllers\VendorController::class, 'resetPassword']);
        /** 最近遊戲商ID列表 recentlyVendorIds */
        Route::post('recently-vendor-ids', [FrontendControllers\VendorController::class, 'recentlyVendorIds']);
        /** 遊戲商費率列表 vendor/rate/list */
        Route::post('rate-list', [FrontendControllers\VendorController::class, 'rateList']);
    });

    Route::prefix('mission')->group(function () {
        /** 任務列表 */
        Route::post('list', [FrontendControllers\MissionController::class, 'list']);

        /** 領取任務獎勵 */
        Route::post('claim', [FrontendControllers\MissionController::class, 'claim']);
    });

    /** 打卡活動 */
    Route::prefix('checkin')->group(function () {
        /** 打卡紀錄 checkin-list */
        Route::post('records', [FrontendControllers\CheckinController::class, 'checkinRecords']);
        /** 簽到 checkin */
        Route::post('/', [FrontendControllers\CheckinController::class, 'checkin']);
    });

    /** 邀請好友活動 */
    Route::prefix('event-invite')->group(function () {
        /** 查詢活動狀態 */
        Route::post('list', [FrontendControllers\EventInviteController::class, 'list']);
        /** 領取獎勵 */
        Route::post('receive', [FrontendControllers\EventInviteController::class, 'receive']);
    });

    /** 自助上分 */
    Route::prefix('auto-deposit')->group(function () {
        /** 查詢銀行帳號列表 old:bank/getAccount */
        Route::post('/bank-accounts', [FrontendControllers\AutoDepositController::class, 'bankAccounts']);
        /** 查詢/創建訂單 old:bank/order */
        Route::post('/order/create', [FrontendControllers\AutoDepositController::class, 'createOrder']);
        /** 刪除訂單 old:bank/delete */
        Route::post('/order/delete', [FrontendControllers\AutoDepositController::class, 'deleteOrder']);
        /** 上傳圖片 old:bank/uploadImage */
        Route::post('/order/upload-image', [FrontendControllers\AutoDepositController::class, 'uploadImage']);
    });

    /* 2.0會員團隊資訊頁 */
    Route::prefix('member-team')->group(function () {
        /** 邀請好友頁面資訊 */
        Route::post('info', [FrontendControllers\MemberTeamController::class, 'info']);
        /** 業績查詢 */
        Route::post('achievement', [FrontendControllers\MemberTeamController::class, 'achievement']);
        /** 團隊管理 */
        Route::post('team-management', [FrontendControllers\MemberTeamController::class, 'teamManagement']);
        /** 代理報表頁面資訊 */
        Route::post('agent-achievement', [FrontendControllers\MemberTeamController::class, 'agentAchievement']);
    });

    /** 無限代 */
    Route::prefix('unlimit-hierarchy')->group(function () {
        /** 玩家詳細信息 */
        Route::post('', [FrontendControllers\UnlimitHierarchyController::class, 'index']);
        /** 無限代理佣金制度表 */
        Route::post('list', [FrontendControllers\UnlimitHierarchyController::class, 'list']);
        /** 我的玩家 */
        Route::post('direct-players', [FrontendControllers\UnlimitHierarchyController::class, 'directPlayers']);
        /** 我的業績 */
        Route::post('reward', [FrontendControllers\UnlimitHierarchyController::class, 'reward']);
        /** 今日流水 */
        Route::post('flow-amount', [FrontendControllers\UnlimitHierarchyController::class, 'flowAmount']);

        Route::prefix('withdrawal')->group(function () {
            /** 我的提現 */
            Route::post('history', [FrontendControllers\UnlimitHierarchyController::class, 'withdrawalHistory']);
            /** 申請提現 */
            Route::post('create', [FrontendControllers\UnlimitHierarchyController::class, 'withdrawalCreate']);
            /** 提現紀錄 */
            Route::post('record', [FrontendControllers\UnlimitHierarchyController::class, 'withdrawalRecord']);
        });
    });

    Route::post('videos', FrontendControllers\VideoController::class);

    Route::prefix('google-auth')->group(function () {
        /** 取得 Google 2FA 圖片 getGoogleAuthImage */
        Route::post('image', [FrontendControllers\GoogleAuthController::class, 'getImage']);
    });

    Route::prefix('bet-record')->group(function () {
        /** 玩家查詢今日與昨日的累積押注額度 */
        Route::post('accumulated-bets', [FrontendControllers\BetRecordController::class, 'accumulatedBets']);
    });

    /** 股東/份桶 (TP 專屬) */
    Route::prefix('shareholder')->group(function () {
        /* 玩家申請成為股東 */
        Route::post('apply', [FrontendControllers\ShareholderController::class, 'apply']);
    });

    /** TG WebApp（會員 token 呼叫；bot 程式 server 對 server 的接口在 tgbot_auth.php） */
    Route::prefix('tgbot')->group(function () {
        /** 補資料整合（3969：一次補齊必填個資，取代逐一分步 + 重新登入） */
        Route::post('complete-profile', [FrontendControllers\TgBotController::class, 'completeProfile']);
    });
});
