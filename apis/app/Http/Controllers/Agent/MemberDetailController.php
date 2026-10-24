<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MemberDetail\MemberDetailMemberIdRequest;
use App\Http\Requests\Agent\MemberDetail\MemberDetailStatisticsRequest;
use App\Http\Requests\Agent\MemberDetail\MemberDetailVendorUsernameRequest;
use App\Http\Requests\Agent\MemberDetail\MemberDetailViewRequest;
use App\Services\Agent\AgentPermissionScopeService;
use App\Services\Agent\MemberDetailChartsService;
use App\Services\Agent\MemberDetailService;
use App\Services\Agent\MemberDetailStatisticsService;
use App\Services\Agent\MemberDetailVendorService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 會員詳情抽屜（4274）。
 *
 * 依抽屜區塊拆分接口（基本資料／統計摘要／圓餅圖各自獨立），前端並行載入、
 * 各自 loading 與重試——滿足工單 D-T4「部分區塊失敗只錯該區塊」。
 */
#[Group('member (會員詳情抽屜)')]
class MemberDetailController extends Controller
{
    public function __construct(
        private readonly MemberDetailService $detailService,
        private readonly AgentPermissionScopeService $scopeService,
        private readonly MemberDetailStatisticsService $statisticsService,
        private readonly MemberDetailChartsService $chartsService,
        private readonly MemberDetailVendorService $vendorService,
    ) {}

    /**
     * 會員卡片＋基本資料
     *
     * 抽屜頂部區塊：帳號/暱稱/推薦碼/錢包（主錢包＋獎勵錢包）＋基本資料 14 欄
     * （含註冊日期；聯絡電話依「顯示電話」權限於服務端遮蔽）。
     * 範疇＝與會員清單一致（整棵下線）；非線下會員回 member out of scope。
     */
    public function view(MemberDetailViewRequest $request): JsonResponse
    {
        return ApiResponse::success($this->detailService->view(
            agent: $this->getAgentContext()->agent,
            memberId: $request->filled('member_id') ? (int) $request->input('member_id') : null,
            username: $request->input('username'),
        ));
    }

    /**
     * 登入者能不能用抽屜
     *
     * 4438 第 7 項：系統管理者、總代理（含各自子帳號）、客服回 true；其他身份回 false，
     * 前端據此讓會員帳號只顯示純文字、帳號管理保留「功能」下拉。本接口不掛身份門檻。
     */
    public function access(): JsonResponse
    {
        return ApiResponse::success([
            'can_use_drawer' => $this->scopeService->canUseMemberDrawer($this->getAgentContext()->agent),
        ]);
    }

    /**
     * 統計摘要
     *
     * 五組 16 項（投注／彩金與獎勵／推薦／存款／提款），預設全期間、
     * 可帶 start_time/end_time（後台時區牆鐘）期間篩選（口徑見 4274 口徑提案文件）。
     */
    public function statistics(MemberDetailStatisticsRequest $request): JsonResponse
    {
        return ApiResponse::success($this->statisticsService->summary(
            agent: $this->getAgentContext()->agent,
            memberId: (int) $request->input('member_id'),
            startTime: $request->input('start_time'),
            endTime: $request->input('end_time'),
        ));
    }

    /**
     * 圖表（三圓餅）
     *
     * 近 90 天固定時間窗：儲值方式分布／轉點轉入 TOP10／轉點轉出 TOP10（含「其他」合計）。
     */
    public function charts(MemberDetailMemberIdRequest $request): JsonResponse
    {
        return ApiResponse::success($this->chartsService->charts(
            agent: $this->getAgentContext()->agent,
            memberId: (int) $request->input('member_id'),
        ));
    }

    /**
     * 遊戲商下拉
     *
     * 啟用中的遊戲商清單，供抽屜遊戲商資料區塊選擇查詢。
     */
    public function vendorList(): JsonResponse
    {
        return ApiResponse::success($this->vendorService->vendorList());
    }

    /**
     * 會員遊戲商帳號查詢
     *
     * 回傳該會員在指定遊戲商底下「所有」啟用中的帳號（陣列，可能為空）；
     * 一個會員在同一遊戲商可能同時有正式線與活動V2線帳號，皆一併回傳，
     * 各筆附 vendor_info_type（0測試/1正式/2活動v2）供前端分別標示（op_tickets#3236）。
     */
    public function vendorUsername(MemberDetailVendorUsernameRequest $request): JsonResponse
    {
        return ApiResponse::success($this->vendorService->vendorUsername(
            agent: $this->getAgentContext()->agent,
            memberId: (int) $request->input('member_id'),
            vendorId: (int) $request->input('vendor_id'),
        ));
    }
}
