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

/* 直接查詢該代理所顯示的遊戲商權狀態 */
Route::get('vendor', 'AgentVendorController@list2');

/* 測試/debug使用 */
Route::get('RDDebug/Test001', 'RDDebugController@TestCase001');
Route::post('RDDebug/Test001', 'RDDebugController@TestCase001');

/* 不需經過加密的helper */
Route::post('data-convert/php-lang/to/excel', 'InternalHelperController@ReadLanguageResourceAndConvertToExcelFormat');
Route::post('data-convert/excel/to/php-lang', 'InternalHelperController@convert_excel_to_langResourceFormat');

/* 登入前可使用的API(沒驗證TOKEN) */
Route::group([
    'middleware' => 'none_auth'
], function () {
    /* 代理登入 */
    Route::post('login', 'Auth\LoginController@login');
    /* 語系列表 */
    Route::post('languages', 'LanguageController@list');
    /* 確認後台維護模式 */
    Route::post('has_maintenance', 'SystemMaintenanceController@has_maintenance');
});


/* 登入後才可使用的API(驗證TOKEN) */
Route::group([
    'middleware' => 'auth'
], function () {
    /* 代理登出 */
    Route::post('logout', 'Auth\LoginController@logout');

    /* 營運商選單列表 */
    Route::post('agentMenu/list', 'AgentMenuController@list');

    /* 可用模版列表 */
    Route::post('template/list', 'TemplateController@list');

    /* 下拉選單 */
    Route::prefix('dropdown')->group(function () {
        Route::post('agent', 'AgentController@dropdownList');
        Route::post('event', 'EventController@dropdownList');
        /* 抽獎券下拉選單 */
        Route::post('luckydraw', 'LuckyDrawController@dropdownList');
        /* Wjackpot抽獎券下拉選單 */
        Route::post('wjackpot', 'WJackpotController@dropdownList');
    });

    /* 2fa */
    Route::prefix('2fa')->group(function () {
        Route::post('image', 'Google2FAController@getImage');
        Route::post('auth', 'Google2FAController@auth');
    });

    /* 代理 */
    Route::prefix('agent')->group(function () {
        /* 代理列表 */
        Route::post('list', 'AgentController@list');
        /* 代理詳細資料 */
        Route::post('view', 'AgentController@show');
        /* 建立代理 */
        Route::post('add', 'AgentController@create');
        /* 編輯代理 */
        Route::post('edit', 'AgentController@update');
        /* 透過groupID篩選代理 */
        Route::post('filter-by-group', 'AgentController@getAgentNameByGroupFilter');


        /* 代理修改自己密碼 */
        Route::post('changePasswordBySelf', 'AgentController@changePasswordBySelf');
        /* 修改代理密碼 */
        Route::post('changePassword', 'AgentController@changePassword');
        /* 確認自己的密碼 */
        Route::post('confirmPassword', 'AgentController@confirmPassword');
        /* 凍結代理 */
        Route::post('delete', 'AgentController@delete');
        /* 取得帳號所有下層代理 */
        Route::post('dropdownList', 'AgentController@dropdownList');
        /* (新)取得帳號所有下層代理 */
        Route::post('newDropdownList', 'AgentController@newDropdownList');
        /* 代理操作紀錄 */
        Route::post('operate/logs', 'AgentOperationLogController@GetAgentOperationLogSettings');
        /* 代理操作紀錄下拉選單 */
        Route::post('operate-log-types/dropdownList', 'AgentOperationLogController@GetOperateTypeDropdown');
        /* 子帳號相關 */
        Route::prefix('child')->group(function () {
            /* 取得代理的所有子帳號 */
            Route::post('accounts', 'AgentPermissionController@get_child_accounts');
            /* 同步所有子帳號權限 */
            Route::post('permission/sync', 'AgentPermissionController@sync_all_child_permission');
        });

        /* 取得代理進階設置 */
        Route::post('personal-settings', 'AgentPersonalSettingController@GetAgentPersonalSettings');
        /* 更新代理進階設置 */
        Route::post('personal-settings/update', 'AgentPersonalSettingController@UpdateAgentPersonalSettings');

        /* 取得代理分組 個性化設置 */
        Route::post('customized-setting', 'AgentCustomizedSettingController@GetAgentCustomizedSetting');
        /* 取得代理分組 個性化設置 */
        Route::post('customized-settings', 'AgentCustomizedSettingController@GetAgentCustomizedSettings');
        /* 更新代理分組 個性化設置 */
        Route::post('customized-settings/update', 'AgentCustomizedSettingController@UpdateAgentCustomizedSettings');
        /* 塞個性化設置預設值(尚未配置的群組) */
        Route::post('customized-settings/assign-default-value', 'AgentCustomizedSettingController@AutoAssignSettingDefaultValue');

        /* 取得代理各種設置的定義配置(預設值、多語系、設置類型、資料型別) */
        Route::post('setting-defines', 'AgentSettingDefineController@GetAgentSettingDefines');
        /* 新增代理各種設置的定義配置(預設值、多語系、設置類型、資料型別) */
        Route::post('setting-defines/create', 'AgentSettingDefineController@Create');
        /* 產生SQL語法 */
        Route::post('insert-sql-generate', 'AgentSettingDefineController@GenerateInsertSQL');

        Route::prefix('group')->group(function () {
            /* 代理group列表 */
            Route::post('list', 'AgentGroupController@list');
            /* 代理group下拉選單 */
            Route::post('dropdownList', 'AgentGroupController@dropdownList');
            /* 代理group列表 */
            Route::post('create', 'AgentGroupController@create');
            /* 更新修改group列表 */
            Route::post('update', 'AgentGroupController@update');
            /* 複製group並產生新的 */
            Route::post('create-by-copy', 'AgentGroupController@createFromCopy');
        });

        /* 取得代理群組權限 */
        Route::post('agent_group_permissions', 'AgentGroupController@getAgentGroupPermissions');
        /* 更新代理群組權限 */
        Route::post('agent_group_permissions/update', 'AgentGroupController@updateAgentGroupPermissions');

        /* 取得開代理可查看的交易類型 */
        Route::post('cashFlows', 'TransactionController@agentCashFlows');
        /* 取得獎勵類型 */
        Route::post('eventReward', 'TransactionController@eventReward');
        /* 代理移轉(預覽) */
        Route::post('hierarchy-migrate/preview', 'AgentMigrateController@GetAgentHierarchyMigrateResultPreview');
        /* 代理移轉 */
        Route::post('hierarchy-migrate', 'AgentMigrateController@AgentHierarchyMigrateExecute');
        /* 檢查是否有hierarchy異常 */
        Route::post('hierarchy-check', 'AgentMigrateController@checkAnyAgentHierarchyFatalError');
                

        /* SMS */
        Route::prefix('sms')->group(function () {
            /**/
            Route::post('agent_group_setting', 'SmsController@agentGroupSetting');
        });

        Route::post('vendor_image_settings', 'VendorController@getVendorImageSetting');
        Route::post('update_vendor_image_settings', 'VendorController@updateVendorImageSetting');

        Route::prefix('vendor_customized_item')->group(function () {
            /* 客製入口列表 */
            Route::post('list', 'VendorCustomizedItemsController@list');
            /* 排序更新 */
            Route::post('update_sorts', 'VendorCustomizedItemsController@updateSorts');
            /* 取得項目 */
            Route::post('get_item', 'VendorCustomizedItemsController@getItem');
            /* 更新項目 */
            Route::post('save_item', 'VendorCustomizedItemsController@saveItem');
            /* 刪除項目 */
            Route::post('remove_item', 'VendorCustomizedItemsController@removeItem');
            /* 遊戲列表 */
            Route::post('get_games', 'VendorCustomizedItemsController@getGamesByVendorId');
        });
    });


    /* 權限 */
    Route::prefix('agentPermission')->group(function () {
        /* 設定權限 */
        Route::post('setting', 'AgentPermissionController@setting');
        /* 設定權限 */
        Route::post('check', 'AgentPermissionController@check');
    });


    Route::prefix('notice')->group(function () {
        /* 是否開啟自助上分/洗分通知 */
        Route::post('checkNotice', 'NoticeController@checkNotice');
        /* 自助上分/洗分通知 */
        Route::post('autoDepositAndWithdraw', 'NoticeController@autoDepositAndWithdraw');
    });


    Route::prefix('image')->group(function () {
        /* 上傳base64圖片取得雲端路徑 */
        Route::post('base64/upload', 'ImageController@uploadImageToRemote');
    });

    Route::prefix('record')->group(function () {
        /* 比對 summaryBet vs totalBet 的數據是否一致 */
        Route::post('compare/summary-vs-total', 'SummaryBetRecordController@compare_summaryBet_vs_totalBet');
    });
      
    /* 會員 */
    Route::prefix('member')->group(function () {
        /* 會員列表 */
        Route::post('list', 'MemberController@list');
        /* 查詢會員 */
        Route::post('view', 'MemberController@show');
        /* 新增會員 */
        Route::post('add', 'MemberController@create');
        /* 修改會員資料 */
        Route::post('edit', 'MemberController@update');
        /* 修改會員密碼 */
        Route::post('changePassword', 'MemberController@changePassword');
        /* 凍結會員 */
        Route::post('delete', 'MemberController@delete');
        /* 會員稽核流水 */
        Route::post('flowLimit', 'MemberController@flowLimit');
        /* 取消稽核流水 */
        Route::post('cancel_flow_limit', 'MemberController@cancel_flow_limit');
        /* 查詢會員IP歷史紀錄 */
        Route::post('getLoginLog', 'MemberController@getLoginLog');
        /* 查詢會員遊戲帳號資訊總覽 */
        Route::post('vendor/balance', 'VendorBalanceController@try_get_vendor_balance');
    });

    /* 驗證方式 */
    Route::prefix('validation')->group(function () {
        /* 驗證方式列表 */
        Route::post('list', 'MemberValidationTypeController@list');
        /* 驗證方式列表 */
        Route::post('setting', 'MemberValidationController@setting');
    });

    /* 代理的遊戲商設置 */
    Route::prefix('agentVendor')->group(function () {
        /* 遊戲商設定列表 */
        Route::post('list', 'AgentVendorController@list');
        /* 遊戲商設定 */
        Route::post('setting', 'AgentVendorController@setting');
        /* 遊戲商設定(backend) */
        Route::post('settingBackend', 'AgentVendorController@settingBackend');
        /* 遊戲商強制設定 */
        Route::post('force_create', 'AgentVendorController@force_create');
        /* 取得樣板用的基準代理遊戲商設定 */
        Route::post('template', 'AgentVendorController@template_agent_vendors');
        /* 第一層代理遊戲商設定列表 */
        Route::post('hasAgentVendorList', 'AgentVendorController@hasAgentVendorList');
    });

    /* 遊戲類型 */
    Route::prefix('gameType')->group(function () {
        /* 遊戲類型列表 */
        Route::post('list', 'GameTypeController@list');
        /* 遊戲類型詳細資料 */
        Route::post('view', 'GameTypeController@show');
        /* 建立遊戲類型 */
        Route::post('add', 'GameTypeController@create');
        /* 修改遊戲類型 */
        Route::post('edit', 'GameTypeController@update');
        /* 刪除遊戲類型 */
        Route::post('delete', 'GameTypeController@delete');
    });

    /* 遊戲 */
    Route::prefix('game')->group(function () {
        /* 遊戲列表 */
        Route::post('list', 'GameController@list');
        /* 遊戲詳細資料 */
        Route::post('view', 'GameController@show');
        /* 建立遊戲 */
        Route::post('add', 'GameController@create');
        /* 修改遊戲 */
        Route::post('edit', 'GameController@update');
        /* 修改遊戲 */
        Route::post('editVendorInfoGame', 'GameController@editVendorInfoGame');
        /* 新增未分配的vendorInfo */
        Route::post('add/unassign-vendorInfo', 'GameController@insertUnassignVendorInfo');
    });

    /* 會員驗證 */
    Route::prefix('memberValidation')->group(function () {
        /* 會員驗證列表 */
        Route::post('list', 'MemberValidationController@list');
        /* 會員驗證詳情 */
        Route::post('view', 'MemberValidationController@show');
        /* 修改會員驗證 */
        Route::post('edit', 'MemberValidationController@update');
    });

    /* 域名 */
    Route::prefix('agentDomain')->group(function () {
        /* 取得可用域名 */
        Route::post('list', 'AgentDomainController@list');
        /* 取得可用域名詳情 */
        Route::post('view', 'AgentDomainController@show');
        /* 設定域名使用的模版 */
        Route::post('setting', 'AgentDomainController@setting');
        /* 取得自己當前域名 */
        Route::post('self', 'AgentDomainController@self');
    });

    /* 國家相關  */
    Route::prefix('country')->group(function () {
        /* 國家列表 */
        Route::post('dropdown', 'CountryController@dropdown');
        /* 國家列表 */
        Route::post('list', 'CountryController@list');
        /* 國家詳細資料 */
        Route::post('view', 'CountryController@show');
        /* 建立國家 */
        Route::post('add', 'CountryController@create');
        /* 修改國家 */
        Route::post('edit', 'CountryController@update');
        /* 刪除國家 */
        Route::post('delete', 'CountryController@delete');
        /* 國家電話碼列表 */
        Route::post('mobile_list', 'CountryController@moblie_list');
    });

    /* 匯率相關  */
    Route::prefix('currency')->group(function () {
        /* 匯率列表 */
        Route::post('list', 'CurrencyController@list');
        /* 國家詳細資料 */
        Route::post('view', 'CurrencyController@show');
        /* 建立匯率 */
        Route::post('add', 'CurrencyController@create');
        /* 修改匯率 */
        Route::post('edit', 'CurrencyController@update');
        /* 刪除匯率 */
        Route::post('delete', 'CurrencyController@delete');
    });

    /* 第三方分析類型列表 */
    Route::post('analyticType/list', 'AnalyticsController@type');

    /* 第三方分析 */
    Route::prefix('analytics')->group(function () {
        /* 第三方分析列表 */
        Route::post('list', 'AnalyticsController@list');
        /* 第三方分析更新 */
        Route::post('setting', 'AnalyticsController@update');
    });

    /* 公告  */
    Route::prefix('bulletinType')->group(function () {
        /* 公告類型列表 */
        Route::post('list', 'BulletinTypeController@list');
    });

    /* 公告  */
    Route::prefix('bulletin')->group(function () {
        /* 公告列表 */
        Route::post('list', 'BulletinController@list');
        /* 公告明細 */
        Route::post('view', 'BulletinController@show');
        /* 新增公告 */
        Route::post('add', 'BulletinController@create');
        /* 更新公告 */
        Route::post('edit', 'BulletinController@update');
    });

    /* 充值訂單  */
    Route::prefix('orderType')->group(function () {
        /* 訂單類型列表 */
        Route::post('list', 'OrderTypeController@list');

        /* 更新訂單類型列表 */
        Route::post('update', 'OrderTypeController@update');
        
        /* 訂單類型列表(分頁) */
        Route::post('paging-list', 'OrderTypeController@paging_list');

    });

    /* 充值訂單  */
    Route::prefix('order')->group(function () {
        /* 訂單列表 */
        Route::post('list', 'OrderController@list');
        /* 訂單明細 */
        Route::post('view', 'OrderController@show');
        /*取得自助開分訂單*/
        Route::post('getAutoDeposit', 'OrderController@getAutoDeposit');
        /*取得詳細資料*/
        Route::post('getImage', 'OrderController@getImage');
        /*審核訂單*/
        Route::post('reviewOrder', 'OrderController@reviewOrder');
        /* 手動到帳上分 */
        Route::post('manual-deposit', 'OrderController@manualDeposit');
        /* 手動到帳上分 */
        Route::post('reset-status', 'OrderController@resetStatus');
    });

    /* 洗分  */
    Route::prefix('withdrawal')->group(function () {
        /*取得自助洗分訂單*/
        Route::post('list', 'WithdrawalController@list');
        /*取得自助洗分銀行列表*/
        Route::post('showBankList', 'WithdrawalController@showBankList');
        /*取得銀行詳細資料*/
        Route::post('getBankInfo', 'WithdrawalController@getBankInfo');
        /*新增自助洗分銀行*/
        Route::post('addBank', 'WithdrawalController@addBank');
        /*編輯自助洗分銀行*/
        Route::post('editBank', 'WithdrawalController@editBank');
        /*取得自助洗分銀行多語系名稱*/
        Route::post('getBankName', 'WithdrawalController@getBankName');
        /*編輯自助洗分銀行多語系名稱*/
        Route::post('editBankName', 'WithdrawalController@editBankName');
        /*取得自助洗分訂單詳細資料*/
        Route::post('getOrderInfo', 'WithdrawalController@getOrderInfo');
        /*審核自助洗分訂單*/
        Route::post('reviewOrder', 'WithdrawalController@reviewOrder');
        /*修改洗分銀行狀態*/
        Route::post('status', 'WithdrawalController@modifyStatus');

        /*執行代付流程*/
        Route::post('payout-process', 'WithdrawalPayoutController@runPayoutProcess');
        /*取得可用的代付*/
        Route::post('payout/cash_flows', 'WithdrawalPayoutController@getAvailablePayoutTypesByWitdhrawalOrder');

    });

    /* 遊戲商 */
    Route::prefix('vendor')->group(function () {
        /* 遊戲商列表 */
        Route::post('list', 'VendorController@list');
        /* 查詢遊戲商帳號 */
        Route::post('username', 'VendorController@username');
        /* 查詢會員遊戲帳號資訊總覽 */
        Route::post('account/summary', 'VendorController@member_all_vendor_suumary');
        /* 查詢會員遊戲帳號 */
        Route::post('account/list', 'VendorController@vendor_accounts');
        /* 遊戲商下拉選單 */
        Route::post('dropdownList', 'VendorController@dropdownList');
        /* 遊戲列表*/
        Route::post('gameList', 'VendorController@gameList');
        /* 遊戲商詳細資料 */
        Route::post('view', 'VendorController@show');
        /* 建立遊戲商 */
        Route::post('add', 'VendorController@create');
        /* 修改遊戲商 */
        Route::post('edit', 'VendorController@update');
        /*遊戲商列表(bankend)*/
        Route::post('getVendorList', 'VendorController@getVendorList');

    });

    /* 遊戲商維護 */
    Route::prefix('vendor_maintenances')->group(function () {
        /* 遊戲商維護列表 */
        Route::post('list', 'VendorMaintenancesController@list');
        /* 查看單筆遊戲商維護 */
        Route::post('view', 'VendorMaintenancesController@view');
        /* 新增/編輯遊戲商維護 */
        Route::post('update', 'VendorMaintenancesController@update');
    });

    /* 商戶資料 */
    Route::prefix('vendor_info')->group(function () {
        /* 商戶資料列表 */
        Route::post('list', 'VendorInfoController@list');
        /* 商戶資料編輯 */
        Route::post('view', 'VendorInfoController@view');
        /* 商戶資料編輯 */
        Route::post('getVendorGame', 'VendorInfoController@getVendorGame');
        /* 編輯遊戲商維護 */
        Route::post('edit', 'VendorInfoController@edit');
        /* 新增遊戲商維護 */
        Route::post('add', 'VendorInfoController@add');
        /* 新增遊戲商維護 */
        Route::post('getVendorInfoGame', 'VendorInfoController@getVendorInfoGame');        
        /* 查出某個代理使用哪一組vendorInfo */
        Route::post('find-by-agent', 'VendorInfoController@findVendorInfoByAgent');
    });

    /* report */
    Route::prefix('report')->group(function () {
        /* 儲值報表 */
        Route::post('deposit', 'ReportController@deposit');
        /* 轉點報表 */
        Route::post('transaction', 'ReportController@transaction');
        /* 會員註冊報表 */
        Route::post('register', 'ReportController@register');
        /* 營運商下線/會員人數 */
        Route::post('numberOfPeople', 'ReportController@numberOfPeople');
        /* SR02 開洗分合計 */
        Route::post('transactionTotal', 'ReportController@transactionTotal');
        /* SR02 開洗分明細表 */
        Route::post('transactionList', 'ReportController@transactionList');
        /* SSR02 開洗分合計 */
        Route::post('transactionTotalNew', 'ReportController@transactionTotalNew');
        /* SSR02 開洗分明細表 */
        Route::post('transactionListNew', 'ReportController@transactionListNew');
        /* SR02 Agent */
        Route::post('sr02Agent', 'ReportController@sr02Agent');
        /* SR02 Member */
        Route::post('sr02Member', 'ReportController@sr02Member');
        /* SR03 User */
        Route::post('sr03User', 'ReportController@sr03User');
        /* SR03 Agent */
        Route::post('sr03Agent', 'ReportController@sr02Agent');
        /* SR03 Member */
        Route::post('sr03Member', 'ReportController@sr03Member');
        /* SR05 */
        Route::post('sr05', 'ReportController@sr05');
        /* SR06 */
        Route::post('sr06', 'ReportController@sr06');
        /* SR07 */
        Route::post('sr07', 'ReportController@sr07');
        /* SR08 */
        Route::post('sr08', 'ReportController@sr08');
        /* SR11 Top */
        Route::post('sr11Top', 'ReportController@sr11Top');
        /* SR11 Agent */
        Route::post('sr11Agent', 'ReportController@sr11Agent');
        /* SR11 Member */
        Route::post('sr11Member', 'ReportController@sr11Member');
        /* SR11 代理商遊戲平台對帳表合計 */
        Route::post('platformReconciliationTotal', 'ReportController@platformReconciliationTotal');
        /* SR11 代理商遊戲平台對帳表明細 */
        Route::post('platformReconciliationDetail', 'ReportController@platformReconciliationDetail');
        /* SR11 代理商遊戲平台對帳表會員 */
        Route::post('platformReconciliationMember', 'ReportController@platformReconciliationMember');
        /* SR12 */
        Route::post('sr12', 'ReportController@sr12');
        /* SR14 */
        Route::post('sr14', 'ReportController@sr14');
        /* SR15 */
        Route::post('sr15', 'ReportController@sr15');
        /* 活動獎勵領取記錄 */
        Route::post('prizeReport', 'ReportController@prizeReport');
    });

    /* new report*/
    Route::prefix('newreport')->group(function () {
        /* SR11 New */
        Route::post('platformReconciliationTotal', 'NewReportController@platformReconciliationTotal');
        Route::post('platformReconciliationDetail', 'NewReportController@platformReconciliationDetail');
        Route::post('platformReconciliationMember', 'NewReportController@platformReconciliationMember');
    });

    /* v_jackpot */
    Route::prefix('v_jackpot')->group(function () {
        /* SR16 v-jackpot */
        Route::post('period', 'VJackpotController@getPeriod');
        Route::post('member', 'VJackpotController@memberSearch');
        Route::post('ticket', 'VJackpotController@ticketSearch');
        Route::post('winningList', 'VJackpotController@winningList');
        /* 抽獎券手動開獎 */
        Route::post('manualDraw', 'VJackpotController@manualDraw');
    });
    /* w_jackpot */
    Route::prefix('w_jackpot')->group(function () {
        /* SR17 v-jackpot */
        Route::post('period', 'WJackpotController@getPeriod');
        Route::post('member', 'WJackpotController@memberSearch');
        Route::post('ticket', 'WJackpotController@ticketSearch');
        Route::post('winningList', 'WJackpotController@winningList');
        /* 抽獎券手動開獎 */
        Route::post('manualDraw', 'WJackpotController@manualDraw');
    });

    /* 活動 */
    Route::prefix('event')->group(function () {
        /* 活動列表 */
        Route::post('list', 'EventController@list');
        /* 活動詳細資料 */
        Route::post('view', 'EventController@show');
        /* 建立活動 */
        Route::post('add', 'EventController@create');
        Route::post('new_add', 'EventController@newCreate');
        /* 修改活動 */
        Route::post('edit', 'EventController@update');
        Route::post('new_edit', 'EventController@newUpdate');
        /* 修改活動狀態 */
        Route::post('status', 'EventController@modifyStatus');
    });

    /* 登入活動 */
    Route::prefix('loginEvent')->group(function () {
        /* 活動列表 */
        Route::post('list', 'LoginEventController@list');
        /* 活動詳細資料 */
        Route::post('view', 'LoginEventController@show');
        /* 建立登入活動 */
        Route::post('add', 'LoginEventController@create');
        /* 修改登入活動 */
        Route::post('edit', 'LoginEventController@update');
        /* 修改活動狀態 */
        Route::post('status', 'LoginEventController@modifyStatus');
    });

    /* 群發公告 */
    Route::prefix('announcement-messages')->group(function () {
        /* 取得群發公告 */
        Route::post('list', 'AnnouncementMessagesController@list');
        /* 建立群發公告 */
        Route::post('create', 'AnnouncementMessagesController@create');
        /* (軟性)刪除群發公告 */
        Route::post('delete', 'AnnouncementMessagesController@delete');
    });

    /* 組織 */
    Route::prefix('organization')->group(function () {
        /* 取得組織下拉選單 */
        Route::post('dropdownList', 'OrganizationController@dropdownList');
    });
        
    /* redis快取 */
    Route::prefix('internal-helper')->group(function () {
        /* 取得組織下拉選單 */
        Route::post('redis/flushDB', 'InternalHelperController@redis_flushDB');      
    });

    /* 多語系 */
    Route::prefix('language-text')->group(function () {
        /* 多語系文案列表 (DB管理的類型) */
        Route::post('show', 'LanguageTextController@GetLanguageTextsByCode');
        /* 更新多語系文案 (DB管理的類型) */
        Route::post('update', 'LanguageTextController@UpdateLanguageTexts');
    });

    /* 取參數值 */
    Route::post('param/list', 'ParamController@list');

    /* 代理開洗分 */
    Route::post('agentTransaction', 'TransactionController@agent');
    /* 會員開洗分 */
    Route::post('memberTransaction', 'TransactionController@member');
    /* 退還遊戲商轉點失敗時多扣的點數 */
    Route::post('refundTransaction', 'TransactionController@refund');

    /* 獎勵類型 */
    Route::prefix('prizeType')->group(function () {
        /* 獎勵類型列表 */
        Route::post('list', 'PrizeTypeController@list');
        /* 獎勵類型詳細資料 */
        Route::post('view', 'PrizeTypeController@show');
        /* 建立獎勵類型 */
        Route::post('add', 'PrizeTypeController@create');
        /* 修改獎勵類型 */
        Route::post('edit', 'PrizeTypeController@update');
    });

    /* 銀行帳號 */
    Route::prefix('bank')->group(function () {
        /* 銀行帳號列表 */
        Route::post('list', 'BankController@list');
        /* 銀行帳號列表 */
        Route::post('showBankList', 'BankController@showBankList');
        /* 新增銀行 */
        Route::post('add', 'BankController@create');
        /* 新增銀行帳號 */
        Route::post('addAccount', 'BankController@createAccount');
        /* 銀行詳細資料 */
        Route::post('view', 'BankController@show');
        /* 銀行帳號詳細資料 */
        Route::post('showAccount', 'BankController@showAccount');
        /* 編輯銀行資料 */
        Route::post('edit', 'BankController@edit');
        /* 編輯銀行帳號資料 */
        Route::post('editAccount', 'BankController@editAccount');
        /* 新增銀行帳號可使用的銀行列表 */
        Route::post('dropdownList', 'BankController@dropdownList');
        /* 修改銀行帳號狀態 */
        Route::post('status', 'BankController@modifyStatus');
    });

    /* 輸入框類型列表 */
    // Route::post('inputType/list', 'MemberInputController@type');

    /* 無限代 */
    Route::prefix('unlimit')->group(function () {
        /* 無限代用戶查詢 */
        Route::post('memberSearch', 'UnlimitController@getMember');
        /* 原代理業績查詢 */
        Route::post('agentSearch', 'UnlimitController@getAgent');
        /* 無限代提現紀錄 */
        Route::post('withdrawalRecord', 'UnlimitController@getWithdrawalRecord');
        /* 原代理獎勵紀錄查詢 */
        Route::post('transactionList', 'UnlimitController@transactionList');
        /* 原代理獎勵紀錄查詢 */
        Route::post('performance', 'UnlimitController@performance');
    });

    /* SMS */
    Route::prefix('sms')->group(function () {
        Route::post('dropdown', 'SmsController@dropdownList');
        Route::prefix('gateway')->group(function () {
            Route::post('list', 'SmsController@getGatewayList');
            Route::post('show', 'SmsController@getGateway');
            Route::post('save', 'SmsController@modifyGateway');
            Route::post('status', 'SmsController@modifyGatewayStatus');
        });
        Route::prefix('info')->group(function () {
            Route::post('list', 'SmsController@getGatewayInfoList');
            Route::post('show', 'SmsController@getGatewayInfo');
            Route::post('save', 'SmsController@modifyGatewayInfo');
            Route::post('status', 'SmsController@modifyGatewayInfoStatus');
        });
        Route::prefix('group_detail')->group(function () {
            Route::post('save', 'SmsController@saveAgentGroupSetting');
        });
        Route::prefix('text')->group(function () {
            Route::post('save', 'SmsController@saveSmsTextContent');
        });
        Route::post('record', 'SmsController@record');
    });

    /* 廣告位置 */
    Route::prefix('advertisementPath')->group(function () {
        /* 廣告位置列表 */
        Route::post('list', 'AdvertisementPathController@list');
        /* 廣告位置明細 */
        Route::post('view', 'AdvertisementPathController@show');
        /* 新增廣告位置 */
        Route::post('add', 'AdvertisementPathController@create');
        /* 編輯廣告位置 */
        Route::post('edit', 'AdvertisementPathController@update');
        /* 刪除廣告位置 */
        Route::post('delete', 'AdvertisementPathController@delete');
    });

    /* 廣告 */
    Route::prefix('advertisement')->group(function () {
        /* 廣告列表 */
        Route::post('list', 'AdvertisementController@list');
        /* 廣告明細 */
        Route::post('view', 'AdvertisementController@show');
        /* 新增廣告 */
        Route::post('add', 'AdvertisementController@create');
        /* 編輯廣告 */
        Route::post('edit', 'AdvertisementController@update');
    });

    /* 廣告(new) */
    Route::prefix('advertise')->group(function () {
        /* 廣告列表 */
        Route::post('image', 'AdvertiseController@getImage');
        /* 廣告列表 */
        Route::post('list', 'AdvertiseController@list');
        /* 廣告明細 */
        Route::post('view', 'AdvertiseController@show');
        /* 新增廣告 */
        Route::post('add', 'AdvertiseController@create');
        /* 編輯廣告 */
        Route::post('edit', 'AdvertiseController@update');
    });

    /* 分桶 */
    Route::prefix('point_barrel')->group(function () {
        //取得下層代理
        Route::post('agent', 'PointBarrelController@get_agent');
        //持股份設定
        Route::post('point_share', 'PointBarrelController@set_point_share');
        //業績查詢
        Route::post('performance', 'PointBarrelController@performance');
        //取得成本清單
        Route::post('cost', 'PointBarrelController@cost');
        //設定成本
        Route::post('set_cost', 'PointBarrelController@set_cost');
        //結算
        Route::post('settle', 'PointBarrelController@settle');
    });

    /* 代理限額 */
    Route::prefix('transfer_limit')->group(function () {
        //取得限額列表
        Route::post('list', 'TransferLimitController@list');
        //取得群組限額詳細資料
        Route::post('getLimitInfo', 'TransferLimitController@getLimitInfo');
        //更新限額
        Route::post('updateLimit', 'TransferLimitController@updateLimit');
        //取得代理群組列表
        Route::post('getDropDownList', 'TransferLimitController@getDropDownList');
        //新增代理限額
        Route::post('createLimit', 'TransferLimitController@createLimit');
        //取得上下分資料
        Route::post('getRecord', 'TransferLimitController@getRecord');
    });

    /* 遊戲/支付/簡訊成本 */
    Route::prefix('cost')->group(function () {
        //成本清單
        Route::post('list', 'CostController@list');
        //成本設定
        Route::post('setting', 'CostController@setSetting');
    });

    Route::prefix('system_maintenance')->group(function () {
        //白名單清單
        Route::post('list', 'SystemMaintenanceController@list');
        //新增白名單
        Route::post('add', 'SystemMaintenanceController@create');
        //刪除白名單
        Route::post('delete', 'SystemMaintenanceController@delete');
        //維護開關
        Route::post('backendMaintenanceToggleStatus', 'SystemMaintenanceController@backendMaintenanceToggleStatus');
    });

    Route::prefix('announcement')->group(function () {
        //代理公告列表
        Route::post('list', 'AnnouncementController@list');
        //代理公告詳細資料
        Route::post('view', 'AnnouncementController@view');
        //新增代理公告
        Route::post('add', 'AnnouncementController@create');
        //編輯代理公告
        Route::post('edit', 'AnnouncementController@update');
        //刪除代理公告
        Route::post('delete', 'AnnouncementController@delete');
        //公告
        Route::post('showByAgentId', 'AnnouncementController@showByAgentId');
        //取得總代理
        Route::post('getGeneralAgent', 'AnnouncementController@getGeneralAgent');
        //檢查未讀公告
        Route::post('checkUnread', 'AnnouncementController@checkUnread');
        //已讀公告
        Route::post('updateAgentAnnouncementStatus', 'AnnouncementController@updateAgentAnnouncementStatus');
    });

    /* 語系翻譯 */
    Route::post('translate', 'LanguageController@translate');

    /* 一次性資料更新 */
    Route::post('one-times/update/agents/hierarchy_path', 'DbUpdateProcessController@UpdateAgentHierarchyPath');

    /* 代理組別－線上支付配置 */
    Route::post('agent/onlineDeposit/cashFlowList', 'AgentOnlineDepositController@cashFlowList');

    /* 代理組別－線上支付開關 */
    Route::post('agent/onlineDeposit/agentCashFlowToggleStatus', 'AgentOnlineDepositController@agentCashFlowToggleStatus');

    /* 代理組別－更新線上支付配置 */
    Route::post('agent/onlineDeposit/updateAgentCashFlow', 'AgentOnlineDepositController@updateAgentCashFlow');

    /* 代理組別－線上支付配置－支付方式列表 */
    Route::post('agent/onlineDeposit/acfOrderTypeList', 'AgentOnlineDepositController@acfOrderTypeList');

    /* 代理組別－線上支付－支付方式列表開關 */
    Route::post('agent/onlineDeposit/agentCashFlowOrderTypeToggleStatus', 'AgentOnlineDepositController@agentCashFlowOrderTypeToggleStatus');

    /* 代理組別－線上支付－支付方式表單更新 */
    Route::post('agent/onlineDeposit/updateAgentCashFlowOrderType', 'AgentOnlineDepositController@updateAgentCashFlowOrderType');

    /* 代理組別－線上支付－支付方式刪除 */
    Route::post('agent/onlineDeposit/agentCashFlowOrderTypeDelete', 'AgentOnlineDepositController@agentCashFlowOrderTypeDelete');

    /* 系統管理-支付渠道管理 */
    Route::post('agent/onlineDeposit/getCashFlow', 'AgentOnlineDepositController@getCashFlow');
    /* 系統管理-支付渠道管理開關 */
    Route::post('agent/onlineDeposit/cashFlowToggleStatus', 'AgentOnlineDepositController@cashFlowToggleStatus');
    /* 系統管理－更新支付渠道配置 */
    Route::post('agent/onlineDeposit/updateCashFlow', 'AgentOnlineDepositController@updateCashFlow');
    /* 系統管理-取得支付方式 */
    Route::post('agent/onlineDeposit/getOrderTypeList', 'AgentOnlineDepositController@getOrderTypeList');
    /* 系統管理－支付方式設置－支付方式列表開關 */
    Route::post('agent/onlineDeposit/cashFlowOrderTypeToggleStatus', 'AgentOnlineDepositController@cashFlowOrderTypeToggleStatus');
    /* 系統管理－支付方式設置－更新支付方式配置 */
    Route::post('agent/onlineDeposit/updateCashFlowOrderType', 'AgentOnlineDepositController@updateCashFlowOrderType');
    /* 系統管理－支付方式設置－取得支付方式多語系 */
    Route::post('agent/onlineDeposit/getCashFlowOrderTypeName', 'AgentOnlineDepositController@getCashFlowOrderTypeName');
    /* 系統管理－支付方式設置－更新支付方式多語系 */
    Route::post('agent/onlineDeposit/updateCashFlowOrderTypeName', 'AgentOnlineDepositController@updateCashFlowOrderTypeName');
});