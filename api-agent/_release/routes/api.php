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
    /* 代理登入 */
    Route::post('login', 'Auth\LoginController@login');
    /* 語系列表 */
    Route::post('languages', 'LanguageController@list');
});

/* 登入後才可使用的API(驗證TOKEN) */
Route::group([
    'middleware' => 'auth'
], function () {
    /* 代理登出 */
    Route::post('logout', 'Auth\LoginController@logout');

    /* 下拉選單-代理列表 */
    Route::post('dropdown/agent', 'AgentController@dropdownList');

    /* 代理列表 */
    Route::post('agent/list', 'AgentController@list');
    /* 代理詳細資料 */
    Route::post('agent/view', 'AgentController@show');
    /* 建立代理 */
    Route::post('agent/add', 'AgentController@create');
    /* 編輯代理 */
    Route::post('agent/edit', 'AgentController@update');
    /* 代理修改自己密碼 */
    Route::post('agent/changePasswordBySelf', 'AgentController@changePasswordBySelf');
    /* 修改代理密碼 */
    Route::post('agent/changePassword', 'AgentController@changePassword');
    /* 確認自己的密碼 */
    Route::post('agent/confirmPassword', 'AgentController@confirmPassword');
    /* 凍結代理 */
    Route::post('agent/delete', 'AgentController@delete');

    /* 營運商選單列表 */
    Route::post('agentMenu/list', 'AgentMenuController@list');
    /* 設定權限 */
    Route::post('agentPermission/setting', 'AgentPermissionController@setting');

    /* 會員列表 */
    Route::post('member/list', 'MemberController@list');
    /* 查詢會員 */
    Route::post('member/view', 'MemberController@show');
    /* 新增會員 */
    Route::post('member/add', 'MemberController@create');
    /* 修改會員資料 */
    Route::post('member/edit', 'MemberController@update');
    /* 修改會員密碼 */
    Route::post('member/changePassword', 'MemberController@changePassword');
    /* 凍結會員 */
    Route::post('member/delete', 'MemberController@delete');
    /* 會員稽核流水 */
    Route::post('member/flowLimit', 'MemberController@flowLimit');

    /* 驗證方式列表 */
    Route::post('validation/list', 'MemberValidationTypeController@list');
    /* 驗證方式列表 */
    Route::post('validation/setting', 'MemberValidationController@setting');

    /* 可用模版列表 */
    Route::post('template/list', 'TemplateController@list');

    /* 遊戲商設定列表 */
    Route::post('agentVendor/list', 'AgentVendorController@list');
    /* 遊戲商設定 */
    Route::post('agentVendor/setting', 'AgentVendorController@setting');

    /* 遊戲類型列表 */
    Route::post('gameType/list', 'GameTypeController@list');
    /* 遊戲類型詳細資料 */
    Route::post('gameType/view', 'GameTypeController@show');

    /* 遊戲列表 */
    Route::post('game/list', 'GameController@list');
    /* 遊戲詳細資料 */
    Route::post('game/view', 'GameController@show');

    /* 會員驗證列表 */
    Route::post('memberValidation/list', 'MemberValidationController@list');
    /* 會員驗證詳情 */
    Route::post('memberValidation/view', 'MemberValidationController@show');
    /* 修改會員驗證 */
    Route::post('memberValidation/edit', 'MemberValidationController@update');

    /* 取得可用域名 */
    Route::post('agentDomain/list', 'AgentDomainController@list');
    /* 取得可用域名詳情 */
    Route::post('agentDomain/view', 'AgentDomainController@show');
    /* 設定域名使用的模版 */
    Route::post('agentDomain/setting', 'AgentDomainController@setting');

    /* 國家列表 */
    Route::post('country/list', 'CountryController@list');
    /* 匯率列表 */
    Route::post('currency/list', 'CurrencyController@list');

    /* 第三方分析類型列表 */
    Route::post('analyticType/list', 'AnalyticsController@type');
    /* 第三方分析列表 */
    Route::post('analytics/list', 'AnalyticsController@list');
    /* 第三方分析更新 */
    Route::post('analytics/setting', 'AnalyticsController@update');

    /* 公告類型列表 */
    Route::post('bulletinType/list', 'BulletinTypeController@list');
    /* 公告列表 */
    Route::post('bulletin/list', 'BulletinController@list');
    /* 公告明細 */
    Route::post('bulletin/view', 'BulletinController@show');
    /* 新增公告 */
    Route::post('bulletin/add', 'BulletinController@create');
    /* 更新公告 */
    Route::post('bulletin/edit', 'BulletinController@update');

    /* 訂單類型列表 */
    Route::post('orderType/list', 'OrderTypeController@list');
    /* 訂單列表 */
    Route::post('order/list', 'OrderController@list');
    /* 訂單明細 */
    Route::post('order/view', 'OrderController@show');

    /* 遊戲商列表 */
    Route::post('vendor/list', 'VendorController@list');
    /* 查詢遊戲商帳號 */
    Route::post('vendor/username', 'VendorController@username');


    /* 儲值報表 */
    Route::post('report/deposit', 'ReportController@deposit');
    /* 轉點報表 */
    Route::post('report/transaction', 'ReportController@transaction');
    /* 會員註冊報表 */
    Route::post('report/register', 'ReportController@register');
    /* 營運商下線/會員人數 */
    Route::post('report/numberOfPeople', 'ReportController@numberOfPeople');
    /* SR02 開洗分合計 */
    Route::post('report/transactionTotal', 'ReportController@transactionTotal');
    /* SR02 開洗分明細表 */
    Route::post('report/transactionList', 'ReportController@transactionList');
    /* SR02 Agent */
    Route::post('report/sr02Agent', 'ReportController@sr02Agent');
    /* SR02 Member */
    Route::post('report/sr02Member', 'ReportController@sr02Member');
    /* SR03 User */
    Route::post('report/sr03User', 'ReportController@sr03User');
    /* SR03 Agent */
    Route::post('report/sr03Agent', 'ReportController@sr02Agent');
    /* SR03 Member */
    Route::post('report/sr03Member', 'ReportController@sr03Member');
    /* SR05 */
    Route::post('report/sr05', 'ReportController@sr05');
    /* SR06 */
    Route::post('report/sr06', 'ReportController@sr06');
    /* SR07 */
    Route::post('report/sr07', 'ReportController@sr07');
    /* SR11 Top */
    Route::post('report/sr11Top', 'ReportController@sr11Top');
    /* SR11 Agent */
    Route::post('report/sr11Agent', 'ReportController@sr11Agent');
    /* SR11 Member */
    Route::post('report/sr11Member', 'ReportController@sr11Member');
    /* SR11 代理商遊戲平台對帳表合計 */
    Route::post('report/platformReconciliationTotal', 'ReportController@platformReconciliationTotal');
    /* SR11 代理商遊戲平台對帳表明細 */
    Route::post('report/platformReconciliationDetail', 'ReportController@platformReconciliationDetail');
    /* SR11 代理商遊戲平台對帳表會員 */
    Route::post('report/platformReconciliationMember', 'ReportController@platformReconciliationMember');
    /* SR12 */
    Route::post('report/sr12', 'ReportController@sr12');
    /* 活動獎勵領取記錄 */
    Route::post('report/prizeReport', 'ReportController@prizeReport');

    /* 活動列表 */
    Route::post('event/list', 'EventController@list');
    /* 活動詳細資料 */
    Route::post('event/view', 'EventController@show');
    /* 建立活動 */
    Route::post('event/add', 'EventController@create');
    /* 修改活動 */
    Route::post('event/edit', 'EventController@update');

    /* 活動列表 */
    Route::post('loginEvent/list', 'LoginEventController@list');
    /* 活動詳細資料 */
    Route::post('loginEvent/view', 'LoginEventController@show');
    /* 建立登入活動 */
    Route::post('loginEvent/add', 'LoginEventController@create');
    /* 修改登入活動 */
    Route::post('loginEvent/edit', 'LoginEventController@update');

    /* 獎勵類型列表 */
    Route::post('prizeType/list', 'PrizeTypeController@list');
    /* 獎勵類型詳細資料 */
    Route::post('prizeType/view', 'PrizeTypeController@show');
    /* 建立獎勵類型 */
    Route::post('prizeType/add', 'PrizeTypeController@create');
    /* 修改獎勵類型 */
    Route::post('prizeType/edit', 'PrizeTypeController@update');

    /* 取參數值 */
    Route::post('param/list', 'ParamController@list');

    /* 代理開洗分 */
    Route::post('agentTransaction', 'TransactionController@agent');
    /* 會員開洗分 */
    Route::post('memberTransaction', 'TransactionController@member');
    /* 退還遊戲商轉點失敗時多扣的點數 */
    Route::post('refundTransaction', 'TransactionController@refund');


    /* 輸入框類型列表 */
    // Route::post('inputType/list', 'MemberInputController@type');
	
    /* 無限代用戶查詢 */
    Route::post('unlimit/memberSearch', 'UnlimitController@getMember');
    /* 原代理業績查詢 */
    Route::post('unlimit/agentSearch', 'UnlimitController@getAgent');
    /* 無限代提現紀錄 */
    Route::post('unlimit/withdrawalRecord', 'UnlimitController@getWithdrawalRecord');
    /* 原代理獎勵紀錄查詢 */
    Route::post('unlimit/transactionList', 'UnlimitController@transactionList');


});