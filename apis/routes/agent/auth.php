<?php

declare(strict_types=1);

use App\Http\Controllers\Agent as AgentControllers;
use App\Http\Middleware;
use App\Http\Middleware\Agent as AgentMiddlewares;
use App\Http\Middleware\Agent\CheckAgentPermission;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use Illuminate\Support\Facades\Route;

/**
 * 功能級授權（稽核 #13）：各路由以 `CheckAgentPermission:<agent_menus.code>` 標註所需選單，
 * 多個 code 以逗號分隔表示「任一即可」（同一支 API 被多個後台頁面共用時）。
 *
 * code 的對應依據為 WEB_AgentBackend 實際呼叫該端點的頁面所檢查的 `$check_auth[<menu url>]`，
 * 例如 `event_v2/wallet-management.php` 檢查 `event_v2/wallet-management` ⇒ code `event_v2_wallet`。
 */
$middlewares = [
    DecryptRequest::class,
    Middleware\DetectIp::class,
    AgentMiddlewares\DetectLanguage::class,
    AgentMiddlewares\AuthAgent::class,
    AgentMiddlewares\MergeAgentData::class,
    RequestLogger::class,
];

/** 活動下拉：被活動管理／參加／回收／拒絕／圖文各頁共用 */
$eventV2Shared = CheckAgentPermission::class . ':event_v2,event_v2_join,event_v2_recycle,event_v2_reject,event_image_text';
/** 活動本體管理：event_v2/edit.php 與 event_image_text 兩個入口 */
$eventV2Manage = CheckAgentPermission::class . ':event_v2,event_image_text';
/** 代付：會員洗分審核與代理洗分審核兩個入口共用同一組端點 */
$payout = CheckAgentPermission::class . ':withdrawal_order_payout,agent_self_withdrawal_index';
/** 代付停留單人工定案：後台兩頁的按鈕同以此權限控制顯示（target/tickets#4137） */
$manualResolve = CheckAgentPermission::class . ':withdrawal_order_verifying_resolve';
/** 會員遊戲帳號：列表頁與總覽頁共用 */
$memberVendorAccount = CheckAgentPermission::class . ':member_vendor_accounts,member_vendor_account_summary';

Route::middleware($middlewares)->group(function () use ($eventV2Shared, $eventV2Manage, $payout, $manualResolve, $memberVendorAccount) {

    Route::prefix('report')->group(function () {
        Route::post('sr12', [AgentControllers\ReportController::class, 'sr12'])
            ->middleware(CheckAgentPermission::class . ':report_sr12');
        Route::post('sr25', [AgentControllers\EventV2Controller::class, 'transactionList'])
            ->middleware(CheckAgentPermission::class . ':report_sr25');

        /** SR02N 開洗分明細表（target/tickets#4356）：列表 / 合計 / 來源支付商三層樹 */
        Route::prefix('sr02n')->middleware(CheckAgentPermission::class . ':report_sr02n')->group(function () {
            Route::post('list', [AgentControllers\Report\Sr02nController::class, 'list']);
            Route::post('total', [AgentControllers\Report\Sr02nController::class, 'total']);
            Route::post('cash-flow-tree', [AgentControllers\Report\Sr02nController::class, 'cashFlowTree']);
        });

        /** SR28 代理營運彙總表（target/tickets#4362） */
        Route::post('sr28', [AgentControllers\Report\AgentOperationSummaryController::class, 'list'])
            ->middleware(CheckAgentPermission::class . ':report_sr28');

        /** SR29 代理遊戲明細表（target/tickets#4318）：主表與所有下鑽層共用同一支 */
        Route::post('sr29', [AgentControllers\AgentGameDetailController::class, 'list'])
            ->middleware(CheckAgentPermission::class . ':report_sr29');

        /** 代理數據看板（target/tickets#4180）：指標總覽 / 留存矩陣 / 代理明細 */
        Route::middleware(CheckAgentPermission::class . ':report_agent_dashboard')
            ->prefix('agent-dashboard')
            ->group(function () {
                Route::post('overview', [AgentControllers\AgentDashboardController::class, 'overview']);
                Route::post('retention', [AgentControllers\AgentDashboardController::class, 'retention']);
                Route::post('agent-list', [AgentControllers\AgentDashboardController::class, 'agentList']);
                Route::post('detail', [AgentControllers\AgentDashboardController::class, 'detail']);
                Route::post('detail-export', [AgentControllers\AgentDashboardController::class, 'detailExport']);
            });
    });

    // 人工限制登入/解除限制（sys_lock，需 agent_sys_lock 權限）
    Route::post('sys-lock/update', [AgentControllers\SysLockController::class, 'update']);

    /** V1 活動管理列表（target/tickets#4320）：自 API_Agent EventController@list 遷入，改後端分頁＋排序；後台頁 event/index */
    Route::prefix('event')->middleware(CheckAgentPermission::class . ':event_list')->group(function () {
        Route::post('list', [AgentControllers\EventController::class, 'list']);
    });

    Route::prefix('event-v2')->group(function () use ($eventV2Shared, $eventV2Manage) {
        Route::post('event-list', [AgentControllers\EventV2Controller::class, 'eventList'])
            ->middleware($eventV2Shared);
        Route::post('join-list', [AgentControllers\EventV2Controller::class, 'joinList'])
            ->middleware(CheckAgentPermission::class . ':event_v2_join');
        Route::post('recycle-list', [AgentControllers\EventV2Controller::class, 'recycleList'])
            ->middleware(CheckAgentPermission::class . ':event_v2_recycle');
        Route::post('reject-list', [AgentControllers\EventV2Controller::class, 'rejectList'])
            ->middleware(CheckAgentPermission::class . ':event_v2_reject');
        Route::post('reject-detail', [AgentControllers\EventV2Controller::class, 'rejectDetail'])
            ->middleware(CheckAgentPermission::class . ':event_v2_reject');
        Route::post('allow-join', [AgentControllers\EventV2Controller::class, 'allowJoin'])
            ->middleware(CheckAgentPermission::class . ':event_v2_reject');
        Route::post('list', [AgentControllers\EventV2Controller::class, 'list'])
            ->middleware($eventV2Manage);
        Route::post('detail', [AgentControllers\EventV2Controller::class, 'detail'])
            ->middleware($eventV2Manage);
        Route::post('create', [AgentControllers\EventV2Controller::class, 'create'])
            ->middleware(CheckAgentPermission::class . ':event_v2');
        Route::post('update', [AgentControllers\EventV2Controller::class, 'update'])
            ->middleware(CheckAgentPermission::class . ':event_v2');
        // 建立／編輯活動時選目標用戶（target/tickets#4234），權限同 create／update
        Route::post('target-options', [AgentControllers\EventV2Controller::class, 'targetOptions'])
            ->middleware(CheckAgentPermission::class . ':event_v2');
        Route::post('update-info', [AgentControllers\EventV2Controller::class, 'updateInfo'])
            ->middleware(CheckAgentPermission::class . ':event_image_text');
        Route::post('wallet-management', [AgentControllers\EventV2Controller::class, 'walletManagement'])
            ->middleware(CheckAgentPermission::class . ':event_v2_wallet');
        Route::post('update-wallet-balance', [AgentControllers\EventV2Controller::class, 'updateWalletBalance'])
            ->middleware(CheckAgentPermission::class . ':event_v2_wallet');
        Route::post('quit', [AgentControllers\EventV2Controller::class, 'quit'])
            ->middleware(CheckAgentPermission::class . ':event_v2_wallet');
    });

    /**
     * 新人專享挑戰（4495 補權限節點）：設定→設定頁、參加紀錄／錢包調整／強制取消／監控／操作日誌→紀錄查詢頁、
     * 重複阻擋名單→阻擋名單頁，節點對應 agent_menus.php 的 newbie_challenge_*。
     */
    Route::prefix('newbie-challenge')->group(function () {
        $setting = CheckAgentPermission::class . ':newbie_challenge_setting';
        $record = CheckAgentPermission::class . ':newbie_challenge_record';
        $block = CheckAgentPermission::class . ':newbie_challenge_block';

        Route::post('config-list', [AgentControllers\NewbieChallengeController::class, 'configList'])->middleware($setting);
        Route::post('config-detail', [AgentControllers\NewbieChallengeController::class, 'configDetail'])->middleware($setting);
        Route::post('config-create', [AgentControllers\NewbieChallengeController::class, 'configCreate'])->middleware($setting);
        Route::post('config-update', [AgentControllers\NewbieChallengeController::class, 'configUpdate'])->middleware($setting);
        Route::post('config-update-status', [AgentControllers\NewbieChallengeController::class, 'configUpdateStatus'])->middleware($setting);
        Route::post('update-wallet-balance', [AgentControllers\NewbieChallengeController::class, 'updateWalletBalance'])->middleware($record);
        Route::post('quit', [AgentControllers\NewbieChallengeController::class, 'quit'])->middleware($record);
        Route::post('progress-list', [AgentControllers\NewbieChallengeController::class, 'progressList'])->middleware($record);
        Route::post('progress-detail', [AgentControllers\NewbieChallengeController::class, 'progressDetail'])->middleware($record);
        Route::post('operation-log-list', [AgentControllers\NewbieChallengeController::class, 'operationLogList'])->middleware($record);
        Route::post('monitor', [AgentControllers\NewbieChallengeController::class, 'monitor'])->middleware($record);
        Route::post('block-list', [AgentControllers\NewbieChallengeController::class, 'blockList'])->middleware($block);
        Route::post('block-detail', [AgentControllers\NewbieChallengeController::class, 'blockDetail'])->middleware($block);
        Route::post('allow-join', [AgentControllers\NewbieChallengeController::class, 'allowJoin'])->middleware($block);
    });

    /**
     * 站點儀表板（4155）：選單首頁下方。以總代切割，admin 可挑站或 ALL，
     * 其他人鎖自己所屬總代線。日結 D-1。
     */
    Route::middleware(CheckAgentPermission::class . ':site_dashboard')
        ->prefix('site-dashboard')->group(function () {
            /** 站點按鈕清單（admin 回 ALL＋全部總代） */
            Route::post('sites', [AgentControllers\SiteDashboardController::class, 'sites']);
            /** 數字卡（期間新註冊、玩家總數、代理總數） */
            Route::post('summary', [AgentControllers\SiteDashboardController::class, 'summary']);
            /** 三張圖的日序列 */
            Route::post('trends', [AgentControllers\SiteDashboardController::class, 'trends']);
        });

    /**
     * 三層代（含代理）模式：用戶查詢（4190）、派發紀錄（4199）、提現紀錄（4214），
     * 由 API_Agent 對應接口移植並強化。舊後台仍用 API_Agent 原接口，兩者互不影響。
     */
    Route::middleware(CheckAgentPermission::class . ':three_layers_user_search')
        ->prefix('three-hierarchy')->group(function () {
            Route::post('user-search', [AgentControllers\ThreeHierarchyController::class, 'userSearch']);
        });
    Route::middleware(CheckAgentPermission::class . ':three_layers_payout_record')
        ->prefix('three-hierarchy')->group(function () {
            Route::post('payout-record', [AgentControllers\ThreeHierarchyController::class, 'payoutRecord']);
            Route::post('payout-record/detail', [AgentControllers\ThreeHierarchyController::class, 'payoutRecordDetail']);
        });
    Route::middleware(CheckAgentPermission::class . ':three_layers_withdrawal_record')
        ->prefix('three-hierarchy')->group(function () {
            Route::post('withdraw-record', [AgentControllers\ThreeHierarchyController::class, 'withdrawRecord']);
        });

    Route::prefix('mission')->group(function () {
        Route::post('claim-record', [AgentControllers\MissionController::class, 'claimRecord'])
            ->middleware(CheckAgentPermission::class . ':claim_record');
    });

    /** 社群入口配置（4106）：營運商管理 → 社群入口配置，設定綁總代、下線共用 */
    Route::middleware(CheckAgentPermission::class . ':social_link_management')->prefix('social-link')->group(function () {
        /** 清單（含是否需選擇代理的旗標） */
        Route::post('list', [AgentControllers\SocialLinkController::class, 'list']);
        /** 整批儲存（新增／更新／刪除／排序） */
        Route::post('save', [AgentControllers\SocialLinkController::class, 'save']);
        /** 總代下拉（僅 super admin 可用） */
        Route::post('agent-dropdown', [AgentControllers\SocialLinkController::class, 'agentDropdown']);
    });

    /** SEO 元數據（4102）：推廣管理 → SEO 元數據，設定綁域名（總代已登記網域）、同線可設一個預設回退 */
    Route::middleware(CheckAgentPermission::class . ':seo_meta_management')
        ->prefix('seo-meta')->group(function () {
            Route::post('agent-dropdown', [AgentControllers\SeoMetaController::class, 'agentDropdown']);
            Route::post('domain-dropdown', [AgentControllers\SeoMetaController::class, 'domainDropdown']);
            Route::post('list', [AgentControllers\SeoMetaController::class, 'list']);
            Route::post('save', [AgentControllers\SeoMetaController::class, 'save']);
        });

    /**
     * 遊戲維護（4120）：系統管理 → 遊戲商維護 → 遊戲維護。
     * 粒度到單一遊戲、一張單可跨遊戲商；與 vendor_maintenances（整商）平行不影響。
     */
    Route::middleware(CheckAgentPermission::class . ':risk_game_restriction')->prefix('game-maintenance')->group(function () {
        /** 維護單列表（遊戲商篩選採「涵蓋即列出」） */
        Route::post('list', [AgentControllers\GameMaintenanceController::class, 'list']);
        /** 單筆詳情（含涵蓋的遊戲清單，供編輯回填） */
        Route::post('view', [AgentControllers\GameMaintenanceController::class, 'view']);
        /** 新增／編輯（帶 id 為編輯；遊戲清單整批覆寫） */
        Route::post('save', [AgentControllers\GameMaintenanceController::class, 'save']);
        /** 表單用可勾選遊戲清單（依遊戲商＋類型過濾，過濾條件不落地） */
        Route::post('game-list', [AgentControllers\GameMaintenanceController::class, 'gameList']);
    });

    /**
     * 會員遊戲限制（4126）：下線會員管理 → 會員遊戲限制。
     * 與 4120 遊戲維護平行——對象是會員或會員群組、永久生效無時間區間；
     * 前台效果相同（灰化），判定併入 VendorService 既有查詢，前端零改動。
     */
    Route::middleware(CheckAgentPermission::class . ':risk_game_restriction')->prefix('member-game-restriction')->group(function () {
        /** 限制單列表 */
        Route::post('list', [AgentControllers\MemberGameRestrictionController::class, 'list']);
        /** 單筆詳情（含受限標的與對象，供編輯回填） */
        Route::post('view', [AgentControllers\MemberGameRestrictionController::class, 'view']);
        /** 新增／編輯（帶 id 為編輯；標的與對象整批覆寫） */
        Route::post('save', [AgentControllers\MemberGameRestrictionController::class, 'save']);
        /** 表單用會員遠端搜尋（未輸入關鍵字不查、限量回傳） */
        Route::post('member-list', [AgentControllers\MemberGameRestrictionController::class, 'memberList']);
    });

    /**
     * 站內信管理 工單4152
     *
     * 對會員發信：指定會員／會員群組（存檔時展開收件人）、
     * 全平臺／代理及子代理（動態判定）。發布時間到點後整封信鎖定僅能查看。
     */
    Route::middleware(CheckAgentPermission::class . ':member_mail_management')->prefix('member-mail')->group(function () {
        /** 站內信列表 */
        Route::post('list', [AgentControllers\MemberMailController::class, 'list']);
        /** 單筆詳情（含多語系與對象，供編輯回填） */
        Route::post('view', [AgentControllers\MemberMailController::class, 'view']);
        /** 新增／編輯（帶 id 為編輯；已發布擋下） */
        Route::post('save', [AgentControllers\MemberMailController::class, 'save']);
        /** 刪除（已發布擋下） */
        Route::post('delete', [AgentControllers\MemberMailController::class, 'delete']);
        /** 表單用會員遠端搜尋（未輸入關鍵字不查、限量回傳） */
        Route::post('member-list', [AgentControllers\MemberMailController::class, 'memberList']);
    });

    /** 域名管理（4035）：系統管理 → 域名管理，管理 domains（後台系統網域） */
    Route::middleware(CheckAgentPermission::class . ':system_domain')->prefix('domain-manage')->group(function () {
        /** 域名列表 */
        Route::post('list', [AgentControllers\DomainManageController::class, 'list']);
        /** 類型下拉選單 */
        Route::post('type-list', [AgentControllers\DomainManageController::class, 'typeList']);
        /** 域名詳情 */
        Route::post('view', [AgentControllers\DomainManageController::class, 'view']);
        /** 新增域名 */
        Route::post('create', [AgentControllers\DomainManageController::class, 'create']);
        /** 修改域名 */
        Route::post('update', [AgentControllers\DomainManageController::class, 'update']);
    });

    Route::middleware(CheckAgentPermission::class . ':member_group')->prefix('member-group')->group(function () {
        /** 會員群組列表 */
        Route::post('list', [AgentControllers\MemberGroupController::class, 'list']);
        /**
         * 會員群組下拉選單
         *
         * 另被會員帳號管理（member/index.vue）與會員編輯（member/edit.vue）當篩選／欄位下拉使用，
         * 故一併放行 member / add_member，否則沒有群組管理權限的代理連會員頁都開不了。
         */
        Route::post('dropdown-list', [AgentControllers\MemberGroupController::class, 'dropdownList'])
            ->withoutMiddleware(CheckAgentPermission::class . ':member_group')
            ->middleware(CheckAgentPermission::class . ':member_group,member,add_member');
        /** 會員群組詳情 */
        Route::post('view', [AgentControllers\MemberGroupController::class, 'view']);
        /** 新增會員群組 */
        Route::post('create', [AgentControllers\MemberGroupController::class, 'create']);
        /** 修改會員群組 */
        Route::post('update', [AgentControllers\MemberGroupController::class, 'update']);
        /** 自助存款帳號勾選清單 */
        Route::post('bank-accounts', [AgentControllers\MemberGroupController::class, 'bankAccounts']);
        /** 指派會員群組 */
        Route::post('assign-member', [AgentControllers\MemberGroupController::class, 'assignMember']);
    });

    /** 風控管理 > 會員限制（4118）：對單一會員或會員群組設定 禁止登入/提款鎖定 */
    Route::middleware(CheckAgentPermission::class . ':risk_member_restriction')->prefix('member-restriction')->group(function () {
        /** 會員限制列表 */
        Route::post('list', [AgentControllers\MemberRestrictionController::class, 'list']);
        /** 會員限制詳情 */
        Route::post('view', [AgentControllers\MemberRestrictionController::class, 'view']);
        /** 新增會員限制 */
        Route::post('create', [AgentControllers\MemberRestrictionController::class, 'create']);
        /** 修改會員限制 */
        Route::post('update', [AgentControllers\MemberRestrictionController::class, 'update']);
        /** 刪除會員限制 */
        Route::post('delete', [AgentControllers\MemberRestrictionController::class, 'delete']);
    });

    /** 風控管理 > IP限制（4096）：狀態啟用的 IP 禁止登入前台（無 delete，關閉狀態即停用） */
    Route::middleware(CheckAgentPermission::class . ':risk_ip_restriction')->prefix('ip-restriction')->group(function () {
        /** IP限制列表 */
        Route::post('list', [AgentControllers\IpRestrictionController::class, 'list']);
        /** IP限制詳情 */
        Route::post('view', [AgentControllers\IpRestrictionController::class, 'view']);
        /** 新增IP限制 */
        Route::post('create', [AgentControllers\IpRestrictionController::class, 'create']);
        /** 修改IP限制 */
        Route::post('update', [AgentControllers\IpRestrictionController::class, 'update']);
    });

    /** 代理模式管理 > 推廣代理 > 遊戲商設置（4123）：GGR 計算的門檻/上限/加權，無 delete（停用靠 status，保 4121 引用） */
    Route::middleware(CheckAgentPermission::class . ':ggr_vendor_setting')->prefix('ggr/vendor-setting')->group(function () {
        /** 遊戲商設置列表 */
        Route::post('list', [AgentControllers\GgrVendorSettingController::class, 'list']);
        /** 遊戲商設置詳情（含巢狀明細） */
        Route::post('view', [AgentControllers\GgrVendorSettingController::class, 'view']);
        /** 新增遊戲商設置 */
        Route::post('create', [AgentControllers\GgrVendorSettingController::class, 'create']);
        /** 修改遊戲商設置（明細全量替換） */
        Route::post('update', [AgentControllers\GgrVendorSettingController::class, 'update']);
        /** 彈窗資料源：總代支援的 vendor_info×game_type 組合與類型 tabs */
        Route::post('vendor-options', [AgentControllers\GgrVendorSettingController::class, 'vendorOptions']);
        /** 排除遊戲下拉（逐列 lazy load） */
        Route::post('game-options', [AgentControllers\GgrVendorSettingController::class, 'gameOptions']);
    });

    /** 代理模式管理 > 推廣代理 > 條件設置（4124）：階梯式派發條件（單一會員門檻），無 delete（停用靠 status，保 4121 引用） */
    Route::middleware(CheckAgentPermission::class . ':ggr_condition_setting')->prefix('ggr/condition-setting')->group(function () {
        /** 條件設置列表 */
        Route::post('list', [AgentControllers\GgrConditionController::class, 'list']);
        /** 條件設置詳情（含階梯列） */
        Route::post('view', [AgentControllers\GgrConditionController::class, 'view']);
        /** 新增條件設置 */
        Route::post('create', [AgentControllers\GgrConditionController::class, 'create']);
        /** 修改條件設置（階梯全量替換） */
        Route::post('update', [AgentControllers\GgrConditionController::class, 'update']);
    });

    /** 代理模式管理 > 推廣代理 > 代理 GGR 設置（4121）：分潤方案（週期/費率/防套利/綁定），無 delete（停用靠 status，保批次三結算引用） */
    Route::middleware(CheckAgentPermission::class . ':ggr_plan_setting')->prefix('ggr/plan')->group(function () {
        /** 方案列表 */
        Route::post('list', [AgentControllers\GgrPlanController::class, 'list']);
        /** 方案詳情（含綁定名稱與特殊用戶列） */
        Route::post('view', [AgentControllers\GgrPlanController::class, 'view']);
        /** 新增方案 */
        Route::post('create', [AgentControllers\GgrPlanController::class, 'create']);
        /** 修改方案（特殊用戶列全量替換） */
        Route::post('update', [AgentControllers\GgrPlanController::class, 'update']);
        /** 表單下拉：本總代啟用中的遊戲商設定 */
        Route::post('vendor-setting-dropdown', [AgentControllers\GgrPlanController::class, 'vendorSettingDropdown']);
        /** 表單下拉：本總代啟用中的條件設定 */
        Route::post('condition-dropdown', [AgentControllers\GgrPlanController::class, 'conditionDropdown']);
    });

    /** 代理模式管理 > 推廣代理 > 推廣代理派發紀錄（4125）：結算結果查詢（純唯讀，資料由結算引擎批次寫入） */
    Route::middleware(CheckAgentPermission::class . ':ggr_payout_record')->prefix('ggr/payout-record')->group(function () {
        /** 派發紀錄列表 */
        Route::post('list', [AgentControllers\GgrSettlementController::class, 'list']);
        /** 派發紀錄詳情（含計算明細快照） */
        Route::post('view', [AgentControllers\GgrSettlementController::class, 'view']);
    });

    Route::prefix('checkin')->group(function () {
        Route::post('list', [AgentControllers\CheckinController::class, 'list'])
            ->middleware(CheckAgentPermission::class . ':checkin_index');
        Route::post('detail', [AgentControllers\CheckinController::class, 'detail'])
            ->middleware(CheckAgentPermission::class . ':checkin_index');
        Route::post('record', [AgentControllers\CheckinController::class, 'record'])
            ->middleware(CheckAgentPermission::class . ':checkin_index,checkin_record');
        Route::post('create', [AgentControllers\CheckinController::class, 'create'])
            ->middleware(CheckAgentPermission::class . ':checkin_index');
        Route::post('update', [AgentControllers\CheckinController::class, 'update'])
            ->middleware(CheckAgentPermission::class . ':checkin_index');
        Route::post('status', [AgentControllers\CheckinController::class, 'updateStatus'])
            ->middleware(CheckAgentPermission::class . ':checkin_index');
    });

    Route::prefix('withdrawal')->group(function () use ($payout, $manualResolve) {
        /** 查詢提款訂單可用的代付渠道 */
        Route::post('channels', [AgentControllers\WithdrawalController::class, 'channels'])
            ->middleware($payout);
        /** 針對玩家 withdrawal_order 請求三方代付（後台管理端呼叫；代理後台的「代理自助提款」頁走 agent/withdrawal，同一個 Controller 方法，舊後台以 source=agent 分流） */
        Route::post('payout-process', [AgentControllers\WithdrawalController::class, 'payout'])
            ->middleware($payout);
        /** 人工查到支付商有單：填寫三方單號，訂單 21 → 22（target/tickets#4137，承接 API_Agent 後台按鈕） */
        Route::post('fill-third-party-no', [AgentControllers\WithdrawalController::class, 'fillThirdPartyNo'])
            ->middleware($manualResolve);
        /** 人工確認支付商查無此單：標記代付失敗，訂單 21/22 → 29（target/tickets#4137） */
        Route::post('mark-failed', [AgentControllers\WithdrawalController::class, 'markFailed'])
            ->middleware($manualResolve);
        /** 提款訂單的三方往返紀錄（後台訂單詳情用；只回純量，往返原文不外流，見 OrderRequestLogDisplayService） */
        Route::post('request-logs', [AgentControllers\WithdrawalController::class, 'requestLogs'])
            ->middleware(CheckAgentPermission::class . ':withdrawal_order_review,agent_self_withdrawal_index');
    });

    /** 代理支付 */
    Route::prefix('deposit')->group(function () {
        /** 取得支付渠道列表 */
        Route::post('channels', [AgentControllers\DepositController::class, 'channels'])
            ->middleware(CheckAgentPermission::class . ':agent_self_deposit_create_fast');
        /** 儲值訂單的三方往返紀錄（後台訂單詳情用；內容已依 provider 白名單過濾，見 OrderRequestLogDisplayService） */
        Route::post('request-logs', [AgentControllers\DepositController::class, 'requestLogs'])
            ->middleware(CheckAgentPermission::class . ':order_review,agent_self_deposit_index');
    });

    Route::middleware($memberVendorAccount)->prefix('vendor')->group(function () {
        /** 後台修改會員遊戲帳號密碼 */
        Route::post('reset-password', [AgentControllers\VendorController::class, 'resetPassword']);
        /** 查詢會員遊戲商錢包餘額（4005，取代 API_Agent 的 api/member/vendor/balance） */
        Route::post('balance', [AgentControllers\VendorController::class, 'balance']);
    });

    /** 廠商管理（target/tickets#4400）：列表含接入通道／gamelist API／回調網址，以及回調後綴編輯 */
    Route::middleware(CheckAgentPermission::class . ':vendor_management')->prefix('vendor-management')->group(function () {
        Route::post('list', [AgentControllers\VendorManagementController::class, 'list']);
        Route::post('callback-path', [AgentControllers\VendorManagementController::class, 'updateCallbackPath']);
    });

    /** 專屬優惠 › 積分紀錄（4232）：主表＋明細，純讀，頁面權限 score_record */
    Route::middleware(CheckAgentPermission::class . ':score_record')->prefix('score/records')->group(function () {
        Route::post('/', [AgentControllers\MemberScoreRecordController::class, 'list']);
        Route::post('logs', [AgentControllers\MemberScoreRecordController::class, 'logs']);
    });

    /**
     * 驗證操作者自己的支付密碼（4438，由 API_Agent verifyAgentPaymentPassword 移植）。
     * 不掛選單碼（會員／代理開洗分與錢包操作四個彈窗共用）；照舊寫 agent_operate_general_logs，支付密碼經遮罩存 ***。
     */
    Route::post('payment-password/verify', [AgentControllers\AgentPaymentPasswordController::class, 'verify'])
        ->middleware(AgentMiddlewares\LogAgentOperation::class);

    Route::prefix('member')->group(function () {
        /** 人工發放積分（4233；開洗分彈窗「積分」選項，權限節點 member_add_score） */
        Route::post('score/grant', [AgentControllers\MemberScoreController::class, 'grant'])
            ->middleware(CheckAgentPermission::class . ':member_add_score');
        /** 人工扣除積分（4233；總量自動依到期日 FIFO 拆批，權限節點 member_deduct_score） */
        Route::post('score/deduct', [AgentControllers\MemberScoreController::class, 'deduct'])
            ->middleware(CheckAgentPermission::class . ':member_deduct_score');
        /** 讀單一會員資料（4438，由 API_Agent member/view 移植；開洗分／補點／會員編輯頁共用，照舊寫 agent_operate_general_logs） */
        Route::post('profile', [AgentControllers\MemberProfileController::class, 'view'])
            ->middleware(AgentMiddlewares\LogAgentOperation::class);
        /** 切換會員狀態（4438，由 API_Agent member/edit_status 移植；照舊寫 agent_operate_general_logs） */
        Route::post('status', [AgentControllers\MemberStatusController::class, 'update'])
            ->middleware([CheckAgentPermission::class . ':member_disabled_permissiom', AgentMiddlewares\LogAgentOperation::class]);
        /**
         * 開洗分彈窗前置資料（4438，由 API_Agent 移植）。
         * 維護狀態不掛選單碼（帳號管理與抽屜決定入口顯不顯示，原接口亦無）、照舊寫 agent_operate_general_logs；
         * 銀行帳號照舊不寫（API_Agent 排除清單有 bank/dropdownAccountList），權限同開洗分入口六項任一。
         */
        Route::post('transaction/maintenance', [AgentControllers\MemberTransactionController::class, 'maintenance'])
            ->middleware(AgentMiddlewares\LogAgentOperation::class);
        Route::post('transaction/bank-accounts', [AgentControllers\MemberTransactionController::class, 'bankAccounts'])
            ->middleware(CheckAgentPermission::class . ':agent_backend_deposit,agent_backend_withdrawal,agent_backend_send_rolling_chip,member_make_up_point,member_add_score,member_deduct_score');
        /**
         * 會員稽核流水列表／取消（4438，由 API_Agent member/flowLimit、member/cancel_flow_limit 移植）。
         * 不掛選單碼（會員列表、會員詳情抽屜、提款審核共用同一個彈窗，原接口亦無）；照舊寫 agent_operate_general_logs。
         */
        Route::post('flow-limits', [AgentControllers\MemberFlowLimitController::class, 'list'])
            ->middleware(AgentMiddlewares\LogAgentOperation::class);
        Route::post('flow-limits/cancel', [AgentControllers\MemberFlowLimitController::class, 'cancel'])
            ->middleware(AgentMiddlewares\LogAgentOperation::class);
        /** 會員活動篩選器（3967，由 API_Agent 移植） */
        Route::post('activity-filter', [AgentControllers\MemberActivityFilterController::class, 'list'])
            ->middleware(CheckAgentPermission::class . ':member_activity_filter');
        /** 在線會員列表 */
        Route::post('online-list', [AgentControllers\MemberOnlineController::class, 'list'])
            ->middleware(CheckAgentPermission::class . ':member_online');
        /** 在線會員強制登出 */
        Route::post('kick', [AgentControllers\MemberOnlineController::class, 'kick'])
            ->middleware(CheckAgentPermission::class . ':member_online');
        /** 會員追蹤（3963，風控查詢關聯帳號） */
        Route::post('trace', [AgentControllers\MemberTraceController::class, 'list'])
            ->middleware(CheckAgentPermission::class . ':member_trace');
        /** 會員追蹤-帳號快搜建議（3963） */
        Route::post('trace/suggest', [AgentControllers\MemberTraceController::class, 'suggest'])
            ->middleware(CheckAgentPermission::class . ':member_trace');
        /** 直屬會員紀錄 */
        Route::post('referral-info', [AgentControllers\MemberReferralInfoController::class, 'list'])
            ->middleware(CheckAgentPermission::class . ':member_referral_info');

        /**
         * 會員詳情抽屜（4274）——刻意不掛 CheckAgentPermission 選單碼：
         * 抽屜由全站 130 個頁面（報表/活動/金流等各種權限）的會員帳號點擊開啟，
         * 綁碼會讓僅持報表權限的人員點不開；基準 API_Agent member/list 亦無選單碼防線。
         * 資料防線＝服務層 ChecksMemberScope（整棵下線範疇，與會員清單一致）；
         * 聯絡電話另依 reveal_phone_number 於服務端遮蔽。
         */
        /** 登入者能不能用抽屜（4438 第 7 項）：前端據此決定會員帳號可不可點、帳號管理要不要保留「功能」下拉 */
        Route::post('detail/access', [AgentControllers\MemberDetailController::class, 'access']);
        /** 身份門檻（4438 第 7 項）：系統管理者、總代理（含子帳號）、客服才能用；其他身份回 9003 */
        Route::prefix('detail')->middleware(AgentMiddlewares\CheckMemberDrawerAccess::class)->group(function () {
            /** 會員卡片＋基本資料 */
            Route::post('view', [AgentControllers\MemberDetailController::class, 'view']);
            /** 統計摘要（16 項，預設全期間、可期間篩選） */
            Route::post('statistics', [AgentControllers\MemberDetailController::class, 'statistics']);
            /** 三圓餅圖（近 90 天） */
            Route::post('charts', [AgentControllers\MemberDetailController::class, 'charts']);
            /** 遊戲商下拉 */
            Route::post('vendor-list', [AgentControllers\MemberDetailController::class, 'vendorList']);
            /** 會員遊戲商帳號查詢 */
            Route::post('vendor-username', [AgentControllers\MemberDetailController::class, 'vendorUsername']);
        });
    });

    /**
     * 代理 Google 2FA（target/tickets#4518 自 API_Agent 2fa/* 搬入；規則見 AgentTwoFactorService）
     *
     * image／auth／enable／disable 只動本人或自己範疇內的代理，不掛選單權限（API_Agent 亦然）；
     * reset-agent 沿用 API_Agent 的兩個權限碼其一。請求參數經 LogAgentOperation 的 maskCredentials 遮罩後落稽核表。
     */
    Route::prefix('2fa')->middleware(AgentMiddlewares\LogAgentOperation::class)->group(function () {
        Route::post('image', [AgentControllers\TwoFactorController::class, 'image']);
        Route::post('auth', [AgentControllers\TwoFactorController::class, 'auth']);
        Route::post('enable', [AgentControllers\TwoFactorController::class, 'enable']);
        Route::post('disable', [AgentControllers\TwoFactorController::class, 'disable']);
        Route::post('reset-agent', [AgentControllers\TwoFactorController::class, 'reset'])
            ->middleware(CheckAgentPermission::class . ':agent_reset_2fa,cj7_user_list_reset_2fa');
    });

    /**
     * 代理後台操作稽核紀錄
     *
     * 不掛選單權限：由各功能頁在自己的權限通過後呼叫，本身無對應選單、也不回傳業務資料。
     */
    Route::prefix('operate-logs')->group(function () {
        /** 記錄後台 Excel 匯出（呼叫端 fail-closed：非 200 不得輸出檔案） */
        Route::post('export', [AgentControllers\AgentOperateLogController::class, 'export']);
    });

    /**
     * 代理組別管理 - 會員欄位設置（agent_group_type=6）
     *
     * 沒有自己的選單，入口在「代理組別管理」列表的功能下拉，故沿用該頁權限碼 agent_group；
     * 組別層的權限（組別管理權）由 service 檢查。
     */
    Route::prefix('member-field-setting')->middleware(CheckAgentPermission::class . ':agent_group')->group(function () {
        Route::post('view', [AgentControllers\MemberFieldSettingController::class, 'view']);
        /** 組別底下是否已有會員（關閉玩家帳號前的確認框用） */
        Route::post('has-members', [AgentControllers\MemberFieldSettingController::class, 'hasMembers']);
        Route::post('fields/save', [AgentControllers\MemberFieldSettingController::class, 'saveFields']);
        Route::post('register-limits/save', [AgentControllers\MemberFieldSettingController::class, 'saveRegisterLimits']);
    });

    /** 商戶管理 - 電子郵件提供商 */
    Route::prefix('email-gateway')->group(function () {
        /**
         * 電子郵件設置組別下拉（比照 /api/sms/group/dropdown，後端已限定 agent_group_type=7）
         *
         * 提供商列表頁與發送紀錄頁都會取這份下拉，故兩個 code 任一即可。
         */
        Route::post('group/dropdown', [AgentControllers\EmailGatewayController::class, 'groupDropdown'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_list,email_gateway_record');
        Route::post('list', [AgentControllers\EmailGatewayController::class, 'list'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_list');
        Route::post('record', [AgentControllers\EmailGatewayController::class, 'record'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_record,email_gateway_list');
        Route::post('record/content', [AgentControllers\EmailGatewayController::class, 'recordContent'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_record,email_gateway_list');
        Route::post('detail', [AgentControllers\EmailGatewayController::class, 'detail'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_list');
        Route::post('create', [AgentControllers\EmailGatewayController::class, 'create'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_list');
        Route::post('update', [AgentControllers\EmailGatewayController::class, 'update'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_list');
        Route::post('status', [AgentControllers\EmailGatewayController::class, 'updateStatus'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_list');
        Route::post('delete', [AgentControllers\EmailGatewayController::class, 'delete'])
            ->middleware(CheckAgentPermission::class . ':email_gateway_list');

        /**
         * 電子郵件設置組別 › 高級設置（工單4286）：群組設定與驗證碼郵件樣板
         *
         * 入口在「代理組別管理」列表的功能下拉，故沿用該頁權限碼 agent_group。
         */
        Route::post('group-setting/view', [AgentControllers\EmailGatewayGroupSettingController::class, 'view'])
            ->middleware(CheckAgentPermission::class . ':agent_group');
        Route::post('group-setting/detail/save', [AgentControllers\EmailGatewayGroupSettingController::class, 'saveDetail'])
            ->middleware(CheckAgentPermission::class . ':agent_group');
        Route::post('group-setting/text/save', [AgentControllers\EmailGatewayGroupSettingController::class, 'saveText'])
            ->middleware(CheckAgentPermission::class . ':agent_group');
    });

    /** 代理 */
    Route::prefix('agent')->group(function () use ($payout) {
        /** 代理充值，針對代理 order 請求三方代收  */
        Route::post('deposit', [AgentControllers\DepositController::class, 'deposit'])
            ->middleware(CheckAgentPermission::class . ':agent_self_deposit_create_fast');
        /** 代理提款，針對代理 withdrawal_order 請求三方代付（代理後台的「代理自助提款」頁呼叫，舊後台帶 source=agent；後台管理端走 withdrawal/payout-process，同一個 Controller 方法） */
        Route::post('withdrawal', [AgentControllers\WithdrawalController::class, 'payout'])
            ->middleware($payout);
        // Route::post('apply-withdrawal', [AgentControllers\CashFlowController::class, 'withdrawal']);
    });

    /**
     * 代理權限（target/tickets#4422 自 API_Agent 搬入，新後台改打這裡；舊後台仍走 API_Agent 同名接口）
     *
     * 角色管理沿用角色管理頁權限碼 permission_group_management；角色下拉跟個人權限存檔同一組（建立／編輯營運商、從頂部「設定」編輯自己）→ add_agent／cj7_add_user；
     * 個人權限存檔照 API_Agent（agent/edit 或 agent/cj7_edit）→ add_agent／cj7_add_user；
     * 子帳號同步、組別管理權更新在 API_Agent 沒檢查選單權限，這裡補上該頁／按鈕的權限碼；
     * 選單樹與權限地圖每頁都要用，只驗身分與線下範疇（在 Service 內）。
     * 兩組都寫 agent_operate_general_logs（照 API_Agent，選單樹除外），見 LogAgentOperation。
     */
    Route::prefix('agent-permission-group')->middleware(AgentMiddlewares\LogAgentOperation::class)->group(function () {
        $manage = CheckAgentPermission::class . ':permission_group_management';
        Route::post('list', [AgentControllers\AgentPermissionGroupController::class, 'list'])->middleware($manage);
        Route::post('view', [AgentControllers\AgentPermissionGroupController::class, 'view'])->middleware($manage);
        Route::post('create', [AgentControllers\AgentPermissionGroupController::class, 'create'])->middleware($manage);
        Route::post('update', [AgentControllers\AgentPermissionGroupController::class, 'update'])->middleware($manage);
        Route::post('status', [AgentControllers\AgentPermissionGroupController::class, 'status'])->middleware($manage);
        Route::post('agents', [AgentControllers\AgentPermissionGroupController::class, 'agents'])->middleware($manage);
        Route::post('owner-dropdown', [AgentControllers\AgentPermissionGroupController::class, 'ownerDropdown'])->middleware($manage);
        // 與個人權限存檔同一組權限碼：只有「添加股東」權限的人從頂部「設定」編輯自己時也要拿得到下拉，
        // 否則下拉空白、存檔時角色給的權限會被整包寫成個人權限
        Route::post('dropdown', [AgentControllers\AgentPermissionGroupController::class, 'dropdown'])
            ->middleware(CheckAgentPermission::class . ':add_agent,cj7_add_user');
    });

    Route::prefix('agent-permission')->middleware(AgentMiddlewares\LogAgentOperation::class)->group(function () {
        Route::post('setting', [AgentControllers\AgentPermissionController::class, 'setting'])
            ->middleware(CheckAgentPermission::class . ':add_agent,cj7_add_user');
        Route::post('sync-children', [AgentControllers\AgentPermissionController::class, 'syncChildren'])
            ->middleware(CheckAgentPermission::class . ':agent_sync_permissiom');
        Route::post('child-accounts', [AgentControllers\AgentPermissionController::class, 'childAccounts']);
        // 選單樹對應 API_Agent 的 agentMenu/list，那邊排除在操作紀錄外，這裡照舊不記
        Route::post('menu-tree', [AgentControllers\AgentPermissionController::class, 'menuTree'])
            ->withoutMiddleware(AgentMiddlewares\LogAgentOperation::class);
        Route::post('code-map', [AgentControllers\AgentPermissionController::class, 'codeMap']);
        Route::post('group-permissions', [AgentControllers\AgentPermissionController::class, 'groupPermissions'])
            ->middleware(CheckAgentPermission::class . ':agent');
        Route::post('group-permissions/update', [AgentControllers\AgentPermissionController::class, 'updateGroupPermissions'])
            ->middleware(CheckAgentPermission::class . ':update_agent_group_permissions');
    });
});
