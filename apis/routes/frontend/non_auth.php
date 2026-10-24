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
    FrontendMiddlewares\HasMember::class,
    FrontendMiddlewares\HasDomain::class,
    FrontendMiddlewares\CheckAgentMaintain::class,
    FrontendMiddlewares\MergeUserData::class,
    RequestLogger::class,
];

Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
    ]);
});

Route::middleware($middlewares)->group(function () {

    Route::prefix('guest')->group(function () {
        /** 註冊會員 */
        Route::post('register', FrontendControllers\RegisterController::class);
        /** 註冊會員 v2（3969：走代理欄位設定的動態驗證與註冊限制；舊 register 不動） */
        Route::post('register-v2', [FrontendControllers\RegisterController::class, 'registerV2']);
        /** 登入會員 */
        Route::post('login', FrontendControllers\LoginController::class);

        /** 發送驗證碼，需傳入驗證過的電子信箱或手機號碼 sendValidateCode */
        Route::post('send-validation-code', [FrontendControllers\ValidationCodeController::class, 'sendCodeToGuest']);
        /**
         * 檢核驗證碼 checkValidateCode
         *
         * 限流（工單 4540）與取得帳號列表共用同一個限流器：這支也能驗忘記密碼的碼，
         * 不限流的話可以在這裡無限猜碼、猜中再去重設密碼，繞過那兩支的限流。順序保證同下。
         */
        Route::post('check-validation-code', [FrontendControllers\ValidationCodeController::class, 'checkCode'])
            ->middleware('throttle:guest-member-accounts');
        /**
         * 忘記密碼 step.2 取得會員帳號列表 checkValidateCode
         *
         * 限流（工單 4540）：IP＋手機＋信箱各自計數、不看 username，規則見 AppServiceProvider::configureGuestPasswordResetRateLimiters()。
         * key 讀的是解密後的欄位、429 訊息要跟著語系，DecryptRequest 與 DetectLanguage 必須先跑；
         * 光掛在路由層不夠（Laravel 會依 priority 把 throttle 提前），先後順序由 bootstrap/app.php 的 middleware priority 設定保證
         */
        Route::post('get-member-accounts', [FrontendControllers\ValidationCodeController::class, 'getMemberNames'])
            ->middleware('throttle:guest-member-accounts');
        /** 忘記密碼 step.3 重設會員密碼 resetPassword（限流：username＋IP 各自計數，順序保證同上） */
        Route::post('reset-password', [FrontendControllers\ValidationCodeController::class, 'resetPassword'])
            ->middleware('throttle:guest-reset-password');

        /** 滑塊驗證出題（4188）*/
        Route::post('captcha', [FrontendControllers\CaptchaController::class, 'create']);
        /** 滑塊驗證判定（4188），通過取得 captcha_token */
        Route::post('captcha/verify', [FrontendControllers\CaptchaController::class, 'verify']);
    });

    Route::prefix('agent')->group(function () {
        /** 透過域名取模版 template */
        Route::post('template', [FrontendControllers\AgentController::class, 'template']);
        /** 依照域名尋找下層代理邀請碼 getAgentCodeByDomain */
        Route::post('downline-code', [FrontendControllers\AgentController::class, 'getDownlineCode']);
        /** 分析代碼列表 analytics */
        Route::post('analytics', [FrontendControllers\AgentController::class, 'getAnalyticsCode']);
    });

    /** 頁尾基本資訊（4104）：關於我們／條款/常見問題/隱私/博彩責任，內容依 Accept-Language */
    Route::post('basic-info', [FrontendControllers\BasicInfoController::class, 'index']);

    /** 大廳用 */
    Route::prefix('lobby')->group(function () {
        /** 遊戲商列表 newVendors */
        Route::post('vendors', [FrontendControllers\LobbyController::class, 'vendors']);
        /** 最新遊戲/熱門遊戲 getGamesByTagByDomain / getGamesByTag */
        Route::post('games', [FrontendControllers\LobbyController::class, 'games']);
    });

    /** 遊戲商 */
    Route::prefix('vendor')->group(function () {
        /** 遊戲商支援的遊戲類別列表 vendor/gameTypes */
        Route::post('game-types', [FrontendControllers\VendorController::class, 'gameTypes']);
    });

    /** 幣種列表 currencies */
    Route::post('currency/list', [FrontendControllers\CurrencyController::class, 'list']);

    /** 國家列表 countries */
    Route::post('country/list', [FrontendControllers\CountryController::class, 'list']);

    /** 廣告列表 (Banner) advertisements */
    Route::post('advertise/list', [FrontendControllers\AdvertiseController::class, 'list']);

    /** 大廳資訊按鈕列表 information */
    Route::post('information/list', [FrontendControllers\InformationController::class, 'list']);

    Route::prefix('announcement-message')->group(function () {
        /** 最新一筆群發公告 announcement-message */
        Route::post('latest', [FrontendControllers\AnnouncementMessageController::class, 'latest']);
        /** 標記已讀群發公告 announcement-message */
        Route::post('read', [FrontendControllers\AnnouncementMessageController::class, 'read']);
    });

    Route::prefix('game')->group(function () {
        /** 遊戲列表 myGames */
        Route::post('list', [FrontendControllers\GameController::class, 'list']);
        /** 按遊戲類型獲取遊戲列表 */
        Route::post('list-by-types', [FrontendControllers\GameController::class, 'listByTypes']);
    });

    /** 活動系統 */
    Route::prefix('event')->group(function () {
        Route::post('list', [FrontendControllers\EventV2Controller::class, 'list']);
    });

    /* APP下載 */
    Route::post('versions', FrontendControllers\VersionController::class);

    Route::prefix('google-auth')->group(function () {
        /** 會員google驗證 */
        Route::post('auth', [FrontendControllers\GoogleAuthController::class, 'auth']);
    });

    Route::prefix('marquee')->group(function () {
        /** 文字跑馬燈 old: marquee/text/getList */
        Route::post('texts', [FrontendControllers\MarqueeController::class, 'texts']);
        /** 贏分跑馬燈列表 old: marquee/win_point/getList */
        Route::post('win-points', [FrontendControllers\MarqueeController::class, 'winPoints']);
    });

    /** 活動系統沿用舊版 */
    Route::prefix('event')->group(function () {
        /** 舊版活動列表（依 domain 判定品牌） eventsByDomain */
        Route::post('legacy-list-by-domain', [FrontendControllers\EventController::class, 'listByDomain']);
    });

    /** 平台累計統計（依 domain 判定品牌）—— 官方形象站首頁「平台核心数据」target/tickets#4145 */
    Route::post('platform-statistic', FrontendControllers\PlatformStatisticController::class);

    /** 股東/份桶 (TP 專屬) */
    Route::prefix('shareholder')->group(function () {
        /** 股東分潤（依 domain 判定品牌） shareholder/profit */
        Route::post('profit', [FrontendControllers\ShareholderController::class, 'getProfit']);
    });

    // 由用戶端呼叫的 api
    Route::prefix('tg-bot')->group(function () {
        /** tg webAppLogin */
        Route::post('web-app-login', [FrontendControllers\TgBotController::class, 'webAppLogin']);
        /** tg oauthLogin */
        Route::post('oauth-login', [FrontendControllers\TgBotController::class, 'oauthLogin']);
        /** 刪除 tg 用戶 */
        Route::post('delete-tg-user', [FrontendControllers\TgBotController::class, 'deleteTgUser']);
        /** 使用 domain 取得 bot 資料 */
        Route::post('get-brand-by-domain', [FrontendControllers\TgBotController::class, 'getBrandByDomain']);
    });

    // 由 tgbot 專案呼叫的 api, 保持駝峰是為了與舊版相容
    Route::prefix('tgBot')->group(function () {
        /** 取得BOT Brands 列表 */
        Route::post('brandList', [FrontendControllers\TgBotController::class, 'getBrandList']);
        /** 取得BOT Brand info */
        Route::post('homePageWithoutLogin', [FrontendControllers\TgBotController::class, 'getBrandInfo']);
        /** 取得BOT 新會員列表 */
        Route::post('newMemberList', [FrontendControllers\TgBotController::class, 'getNewMemberList']);
    });
});
