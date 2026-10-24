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
    /* 會員登入 */
    Route::post('login', 'Auth\LoginController@login');
    /* 語系列表 */
    Route::post('languages', 'LanguageController@list');
});

/* 登入後才可使用的API(驗證TOKEN) */
Route::group([
    'middleware' => 'auth'
], function () {
    /* 會員登出 */
    Route::post('logout', 'Auth\LoginController@logout');
    /* 管理者列表 */
    Route::post('user/list', 'UserController@list');
    /* 管理者詳細資料 */
    Route::post('user/view', 'UserController@show');
    /* 建立管理者 */
    Route::post('user/add', 'UserController@create');
    /* 編輯管理者 */
    Route::post('user/edit', 'UserController@update');
    /* 凍結管理者 */
    Route::post('user/delete', 'UserController@delete');
    /* 修改自己密碼 */
    Route::post('user/changePasswordBySelf', 'UserController@changePasswordBySelf');
    /* 修改管理者密碼 */
    Route::post('user/changePassword', 'UserController@changePassword');

    /* 管理者選單列表 */
    Route::post('userMenu/list', 'UserMenuController@list');
    /* 建立/修改權限 */
    Route::post('userPermission/setting', 'UserPermissionController@setting');

    /* 代理列表 */
    Route::post('agent/list', 'AgentController@list');
    /* 代理明細 */
    Route::post('agent/view', 'AgentController@show');
    /* 建立代理 */
    Route::post('agent/add', 'AgentController@create');
    /* 編輯代理 */
    Route::post('agent/edit', 'AgentController@update');
    /* 刪除代理 */
    Route::post('agent/delete', 'AgentController@delete');

    /* 代理選單列表 */
    Route::post('agentMenu/list', 'AgentMenuController@list');
    /* 建立/修改權限 */
    Route::post('agentPermission/setting', 'AgentPermissionController@setting');

    /* 模版列表 */
    Route::post('template/list', 'TemplateController@list');
    /* 模版詳細資料 */
    Route::post('template/view', 'TemplateController@show');
    /* 新增模版 */
    Route::post('template/add', 'TemplateController@create');
    /* 編輯模版 */
    Route::post('template/edit', 'TemplateController@update');
    /* 停用模版 */
    Route::post('template/delete', 'TemplateController@delete');

    /* 代理模版列表 */
    Route::post('agentTemplate/list', 'AgentTemplateController@list');
    /* 設定代理可用模版 */
    Route::post('agentTemplate/setting', 'AgentTemplateController@setting');

    /* 域名列表 */
    Route::post('agentDomain/list', 'AgentDomainController@list');
    /* 新增域名 */
    Route::post('agentDomain/add', 'AgentDomainController@create');
    /* 編輯域名 */
    Route::post('agentDomain/edit', 'AgentDomainController@update');
    /* 刪除域名 */
    Route::post('agentDomain/delete', 'AgentDomainController@delete');

    /* 國家列表 */
    Route::post('country/list', 'CountryController@list');
    /* 國家詳細資料 */
    Route::post('country/view', 'CountryController@show');
    /* 建立國家 */
    Route::post('country/add', 'CountryController@create');
    /* 修改國家 */
    Route::post('country/edit', 'CountryController@update');
    /* 刪除國家 */
    Route::post('country/delete', 'CountryController@delete');

    /* 匯率列表 */
    Route::post('currency/list', 'CurrencyController@list');
    /* 國家詳細資料 */
    Route::post('currency/view', 'CurrencyController@show');
    /* 建立匯率 */
    Route::post('currency/add', 'CurrencyController@create');
    /* 修改匯率 */
    Route::post('currency/edit', 'CurrencyController@update');
    /* 刪除匯率 */
    Route::post('currency/delete', 'CurrencyController@delete');

    /* 廣告位置列表 */
    Route::post('advertisementPath/list', 'AdvertisementPathController@list');
    /* 廣告位置明細 */
    Route::post('advertisementPath/view', 'AdvertisementPathController@show');
    /* 新增廣告位置 */
    Route::post('advertisementPath/add', 'AdvertisementPathController@create');
    /* 編輯廣告位置 */
    Route::post('advertisementPath/edit', 'AdvertisementPathController@update');
    /* 刪除廣告位置 */
    Route::post('advertisementPath/delete', 'AdvertisementPathController@delete');

    /* 廣告列表 */
    Route::post('advertisement/list', 'AdvertisementController@list');
    /* 廣告明細 */
    Route::post('advertisement/view', 'AdvertisementController@show');
    /* 新增廣告 */
    Route::post('advertisement/add', 'AdvertisementController@create');
    /* 編輯廣告 */
    Route::post('advertisement/edit', 'AdvertisementController@update');

    /* 遊戲類型列表 */
    Route::post('gameType/list', 'GameTypeController@list');
    /* 遊戲類型詳細資料 */
    Route::post('gameType/view', 'GameTypeController@show');
    /* 建立遊戲類型 */
    Route::post('gameType/add', 'GameTypeController@create');
    /* 修改遊戲類型 */
    Route::post('gameType/edit', 'GameTypeController@update');
    /* 刪除遊戲類型 */
    Route::post('gameType/delete', 'GameTypeController@delete');

    /* 遊戲列表 */
    Route::post('game/list', 'GameController@list');
    /* 遊戲詳細資料 */
    Route::post('game/view', 'GameController@show');
    /* 建立遊戲 */
    Route::post('game/add', 'GameController@create');
    /* 修改遊戲 */
    Route::post('game/edit', 'GameController@update');
    /* 刪除遊戲 */
    Route::post('game/delete', 'GameController@delete');

    /* 第三方分析類型列表 */
    Route::post('analyticType/list', 'AnalyticsController@type');
    /* 第三方分析列表 */
    Route::post('analytics/list', 'AnalyticsController@list');
    /* 第三方分析更新 */
    Route::post('analytics/setting', 'AnalyticsController@update');

    /* 公告類型列表 */
    Route::post('bulletinType/list', 'BulletinTypeController@list');
    /* 公告類型明細 */
    Route::post('bulletinType/view', 'BulletinTypeController@show');
    /* 新增公告類型 */
    Route::post('bulletinType/add', 'BulletinTypeController@create');
    /* 更新公告類型 */
    Route::post('bulletinType/edit', 'BulletinTypeController@update');
    /* 刪除公告類型 */
    Route::post('bulletinType/delete', 'BulletinTypeController@delete');

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

    /* 儲值報表 */
    Route::post('report/deposit', 'ReportController@deposit');
    /* 轉點報表 */
    Route::post('report/transaction', 'ReportController@transaction');
    /* 會員註冊報表 */
    Route::post('report/register', 'ReportController@register');
    /* 營運商下線/會員人數 */
    Route::post('report/numberOfPeople', 'ReportController@numberOfPeople');

    /* 活動列表 */
    Route::post('event/list', 'EventController@list');
    /* 活動詳細資料 */
    Route::post('event/view', 'EventController@show');
    /* 建立活動 */
    Route::post('event/add', 'EventController@create');
    /* 修改活動 */
    Route::post('event/edit', 'EventController@update');

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
    /* 編輯語系狀態 */
    Route::post('language/edit', 'LanguageController@update');

    /* 遊戲商列表 */
    Route::post('vendor/list', 'VendorController@list');
    /* 遊戲商詳細資料 */
    Route::post('vendor/view', 'VendorController@show');
    /* 建立遊戲商 */
    Route::post('vendor/add', 'VendorController@create');
    /* 修改遊戲商 */
    Route::post('vendor/edit', 'VendorController@update');
    /* 第一層代理遊戲商設定列表 */
    Route::post('agentVendor/list', 'AgentVendorController@list');
    /* 代理遊戲商顯示設定 */
    Route::post('agentVendor/setting', 'AgentVendorController@setting');

    /* 遊戲商維護時間列表 */
    Route::post('vendorMaintenance/list', 'VendorMaintenanceController@list');
    /* 新增遊戲商維護時間 */
    Route::post('vendorMaintenance/add', 'VendorMaintenanceController@create');
    /* 編輯遊戲商維護時間 */
    Route::post('vendorMaintenance/edit', 'VendorMaintenanceController@update');
    /* 刪除遊戲商維護時間 */
    Route::post('vendorMaintenance/delete', 'VendorMaintenanceController@delete');
});