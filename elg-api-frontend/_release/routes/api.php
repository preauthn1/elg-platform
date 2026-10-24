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
// 給金流商(銀鑫付)回調用
Route::post('callback', 'OrderController@callback');

/* 內部測試小工具 */
Route::post('debug/AES/decrypt', 'RD_DebugController@Decrypt'); 
Route::post('debug/AES/encrypt', 'RD_DebugController@Encrypt'); 
Route::post('debug/SQL/Binding', 'RD_DebugController@SQLBinding'); 
Route::post('debug/TestCase001', 'RD_DebugController@TestCase001'); 
Route::post('debug/TestGetIP', 'RD_DebugController@TestGetIP');

/* 登入前可使用的API(沒驗證TOKEN) */
Route::group([
    'middleware' => 'none_auth'
], function () {
    /* 會員登入 */
    Route::post('login', 'Auth\LoginController@login');
    /* 會員註冊 */
    Route::post('create', 'MemberController@create');
    /* 傳送驗證碼 */
    Route::post('sendValidateCode', 'ValidateTokenController@sendValidateCode');
    /* 確認驗證碼是否正確 */
    Route::post('checkValidateCode', 'ValidateTokenController@checkValidateCode');
    /* 利用驗證碼重設會員密碼 */
    Route::post('resetPassword', 'ValidateTokenController@resetPassword');
    /* 國家列表 */
    Route::post('countries', 'CountryController@list');
    /* 幣種列表 */
    Route::post('currencies', 'CurrencyController@list');
    /* 語系列表 */
    Route::post('languages', 'LanguageController@list');
    /* 時區列表 */
    Route::post('timezones', 'TimezoneController@list');
    /* 廣告列表 */
    Route::post('advertisements', 'AdvertisementController@list');
    /* 取模版 */
    Route::post('template', 'TemplateController@show');
    /* 分析代碼列表 */
    Route::post('analytics', 'AnalyticsController@list');
    /* 遊戲類型列表 */
    Route::post('gameTypes', 'GameTypeController@list');
    /* 遊戲彩金列表 */
    Route::post('lottery', 'GameController@lottery');
    /*APP下載*/
    Route::post('versions', 'VersionController@getVersion');

    /* Alternate domain */
    Route::post('alternate_domain', function () {
        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => explode(',', env('ALTERNATE_DOMAIN')),
        ]);
    });
});

/* 登入後才可使用的API(驗證TOKEN) */
Route::group([
    'middleware' => ['auth' , 'check_maintain' ]
], function () {
    /* 更新Token */
    Route::post('renew/token', 'Auth\LoginController@renew');
    /* 查詢會員 */
    Route::post('member', 'MemberController@show');
    /* 修改會員資料 */
    Route::post('update', 'MemberController@update');
    /* 修改會員密碼 */
    Route::post('changePassword', 'MemberController@changePassword');
    /* 新增會員設定 */
    Route::post('createSetting', 'MemberController@createSetting');
    /* 修改會員設定 */
    Route::post('updateSetting', 'MemberController@updateSetting');

    /* 會員驗證類型列表 */
    // Route::post('validationTypes', 'MemberValidationTypeController@list');
    /* 會員驗證列表 */
    Route::post('validations', 'MemberValidationController@list');
    /* 會員驗證明細 */
    // Route::post('validation', 'MemberValidationController@show');
    /* 新增會員驗證 */
    Route::post('validation/create', 'MemberValidationController@create');
    /* 修改會員驗證 */
    Route::post('validation/update', 'MemberValidationController@update');

    /* 公告類型列表 */
    Route::post('bulletinTypes', 'BulletinTypeController@list');
    /* 公告列表 */
    Route::post('bulletins', 'BulletinController@list');
    /* 公告明細 */
    Route::post('bulletin', 'BulletinController@show');

    /* 遊戲列表(原本為了登入前使用，之後因為代理商會影響遊戲列表，所以改為需登入) */
    // Route::post('games', 'GameController@list');
    Route::post('games', 'GameController@myList');
    /* 遊戲資訊 */
    Route::post('game', 'GameController@show');
    /* 遊戲列表 */
    Route::post('myGames', 'GameController@myList');
    /* 加入/取消我的最愛遊戲 */
    Route::post('favouriteGame', 'GameController@setFavourite');

    /* 遊戲商列表 */
    Route::post('vendors', 'VendorController@list');
    /* 遊戲商列表(轉換比例資訊) */
    Route::post('vendor/rate/list', 'VendorController@rate_list');
    /* 遊戲商資訊 */
    Route::post('vendor', 'VendorController@show');
    /* 遊戲商支援的遊戲類別資訊 */
    Route::post('vendor/gameTypes', 'VendorController@gameTypes');
    /* 取遊戲商登入資訊(需要另外登入的遊戲商才適用) */
    Route::post('vendorInfo', 'VendorController@vendorInfo');

    /* 取得遊戲商餘額 */
    Route::post('getVendorBalance', 'VendorController@getBalance');
    /* 修改遊戲商密碼(需要另外登入的遊戲商才適用) */
    Route::post('resetVendorPassword', 'VendorController@resetPassword');
    /* 進入遊戲的連結 */
    Route::post('getGameUrl', 'VendorController@getGameUrl');
    /* 平台餘額轉帳至遊戲商餘額 */
    Route::post('balanceToVendor', 'VendorController@balanceToVendor');
    /* 遊戲商餘額轉帳至平台餘額 */
    Route::post('vendorToBalance', 'VendorController@vendorToBalance');
    /* 轉點記錄列表 */
    Route::post('transactions', 'VendorController@transactions');
    /* 單一錢包餘額 */
    Route::post('singleWalletBalance', 'VendorController@singleWalletBalance');
    /*依最後一次轉入遊戲商紀錄轉回主錢包*/
    Route::post('transferFromLastLog', 'VendorController@transferFromLastLog');


    /* 紅包記錄列表 */
    Route::post('transaction/redEnvelope', 'TransactionLogController@redEnvelope');
    /* 客服記錄列表 */
    Route::post('transaction/customerService', 'TransactionLogController@customerService');

    /* 最後一次轉點進遊戲記錄 */
    Route::post('lastTransactionToVendor', 'MemberController@lastTransactionToVendor');

    /* 訂單類型列表 */
    Route::post('orderTypes', 'OrderTypeController@list');
    /* 訂單列表 */
    Route::post('orders', 'OrderController@list');
    /* 訂單明細 */
    Route::post('order', 'OrderController@show');
    /* 訂單新增 */
    Route::post('order/create', 'OrderController@create');

    /* 活動列表 */
    Route::post('events', 'EventController@list');
    /* 活動詳情 */
    Route::post('event', 'EventController@view');
    /* 參與活動的遊戲列表 */
    Route::post('event/games', 'EventController@games');
    /* 此遊戲商參與的活動詳情 */
    Route::post('event/vendorGames', 'EventController@gamesByVendor');


    /* 抽獎券活動相關 */
    Route::post('event/lucky-draw/issues', 'EventLuckyDrawController@issue_list');
    Route::post('event/lucky-draw/issues/detail', 'EventLuckyDrawController@issue_detail');
    Route::post('event/lucky-draw/issues/tickets', 'EventLuckyDrawController@issue_tickets');
    Route::post('event/lucky-draw/issues/receive', 'EventLuckyDrawController@receive');
    Route::post('event/lucky-draw/issues/receive-all', 'EventLuckyDrawController@receive_all');



    /* 取得個人中獎列表 */
    Route::post('prizes', 'PrizeController@list');
    /* 取得摸彩活動中獎列表 */
    Route::post('lotteryNumbers', 'PrizeController@lotteryNumbers');
    /* 取得自己的摸彩卷號碼列表 */
    Route::post('myLotteryNumbers', 'PrizeController@myLotteryNumbers');
    /* 取得中獎資料 */
    Route::post('getPrize', 'PrizeController@getPrize');
    /* 設定中獎項目為已領取 */
    Route::post('setPrize', 'PrizeController@setPrize');

    /* 取登入活動 */
    Route::post('getLoginEvent', 'EventController@getLoginEvent');
    /* 確認是否可以參加登入活動 */
    Route::post('checkLoginEvent', 'EventController@checkLoginEvent');
    /* 參加登入活動 */
    Route::post('joinLoginEvent', 'EventController@joinLoginEvent');
    /* 登入活動解鎖 */
    Route::post('unlockLoginEvent', 'EventController@unlockLoginEvent');

    

    /* 提供前端寫一些debug資訊 */
    Route::post('debug/write_log', 'DebugInfoController@write_log');

    /* 會員登出 */
    Route::post('logout', 'Auth\LoginController@logout');

    /* 獲取所有支付方式的列表 */
    Route::post('deposit/getOrderType', 'DepositController@getOrderType');
    /* 支付訂單 */
    Route::post('deposit/order', 'DepositController@order');

     /* 取得群發公告 */
    Route::post('announcement-messages/list', 'AnnouncementMessagesController@list');
    
     /* 群發公告設置已讀 */
    Route::post('announcement-messages/read', 'AnnouncementMessagesController@read');



    /* 銀鑫付-支付寶 */
    // Route::post('aliPay', 'YXFController@alipay');
    /* 銀鑫付-銀聯 */
    // Route::post('unionPay', 'YXFController@unionPay');
    /* NCPAY TEST */
    // Route::post('ncpay', 'PaymentController@test');
    // Route::post('transfer', 'PaymentController@transfer');
    // Route::post('transferCheck', 'PaymentController@transferCheck');
    // Route::post('transferList', 'PaymentController@transferList');
    // Route::post('deposit', 'PaymentController@deposit');
    // Route::post('depositCheck', 'PaymentController@depositCheck');
    // Route::post('depositList', 'PaymentController@depositList');
    // Route::post('bankList', 'PaymentController@bankList');

    /*  */
	Route::post('unlimit', 'UnlimitController@index');
	/*  */
	Route::post('unlimit/list', 'UnlimitController@list');
	/* 我的玩家 */
	Route::post('unlimit/directPlayers', 'UnlimitController@directPlayers');
	/* 我的獎勵 */
	Route::post('unlimit/reward', 'UnlimitController@reward');
	/* 我的提現 */
	Route::post('unlimit/history', 'UnlimitController@getWithdrawHistory');
	/* 提現 */
	Route::post('unlimit/withdraw', 'UnlimitController@withdrawal');
	/* 提現紀錄欄 */
    Route::post('unlimit/withdrawRecode', 'UnlimitController@getWithdrawal');

    /*判斷是否顯示自動上分按鈕*/
    Route::post('bank/hasAutoDespoiste', 'BankController@hasAutoDespoiste');
    /*取得銀行帳號*/
    Route::post('bank/getAccount', 'BankController@show');
    /*查詢/創建訂單*/
    Route::post('bank/order', 'BankController@order');
    /*刪除訂單*/
    Route::post('bank/delete', 'BankController@delete');
    /*上傳收據圖片*/
    Route::post('bank/uploadImage', 'BankController@uploadImage');

    /*檢查是否有綁定銀行帳號*/
    Route::post('withdrawal/check', 'WithdrawalController@check');
    /*取得銀行清單*/
    Route::post('withdrawal/getBankList', 'WithdrawalController@getBankList');
    /*綁定銀行帳號*/
    Route::post('withdrawal/account', 'WithdrawalController@account');
    /*新建自助洗分訂單*/
    Route::post('withdrawal', 'WithdrawalController@withdrawal');
    /*自助洗分紀錄*/
    Route::post('withdrawal/list', 'WithdrawalController@withdrawalList');
    /* 影城 */
    Route::post('videos', 'VideoController@openApp');

    /* 稽核流水 */
    Route::prefix('flowLimit')->group(function () {
        /* 當前稽核狀態 */
        Route::post('show', 'FlowLimitController@show');
        /* 稽核列表 */
        Route::post('list', 'FlowLimitController@list');
    });
});

Route::group([
    'middleware' => 'third_party_auth'
], function () {
    /* 取餘額 */
    Route::post('getInfo', 'ThirdPartyController@info');
    /* 確認密碼 */
    Route::post('checkPassword', 'ThirdPartyController@check_password');
    /* 紅包，開洗分 */
    Route::post('transaction', 'ThirdPartyController@transaction');
    /* 代理之間轉帳 */
    Route::post('agentTransaction', 'ThirdPartyController@agentTransaction');
    /* 當前稽核狀態 */
    Route::post('checkFlowLimit', 'ThirdPartyController@checkFlowLimit');
    /* CS平臺介面設定 */
    Route::post('csSetting', 'ThirdPartyController@csSetting');
});