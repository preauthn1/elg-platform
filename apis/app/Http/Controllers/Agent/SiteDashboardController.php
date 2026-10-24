<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\SiteDashboard\SiteDashboardRequest;
use App\Services\Agent\SiteDashboardService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 站點儀表板（4155）：選單首頁下方的獨立儀表板。
 *
 * 以總代（站點）為切割：admin 可挑任一總代或 ALL 全站；
 * 其他人（含總代自己）鎖定自己所屬的總代線。
 * 一律日結 D-1，今日資料不出現；D-1 資料不變故整包快取一小時。
 */
#[Group('site-dashboard (站點儀表板)')]
class SiteDashboardController extends Controller
{
    public function __construct(
        private readonly SiteDashboardService $service,
    ) {}

    /**
     * 站點按鈕清單
     *
     * admin 回 ALL＋全部總代；其他人只回自己所屬的總代。
     */
    public function sites(): JsonResponse
    {
        $viewer = $this->getAgentContext()->agent;

        return ApiResponse::success($this->service->sites($viewer));
    }

    /**
     * 數字卡（期間新註冊、玩家總人數、代理總人數）
     */
    public function summary(SiteDashboardRequest $request): JsonResponse
    {
        $viewer = $this->getAgentContext()->agent;

        return ApiResponse::success($this->service->summary($viewer, $request->validated()));
    }

    /**
     * 三張圖的日序列（新註冊柱狀、充提雙柱、投注/輸贏/獎勵三折線）
     */
    public function trends(SiteDashboardRequest $request): JsonResponse
    {
        $viewer = $this->getAgentContext()->agent;

        return ApiResponse::success($this->service->trends($viewer, $request->validated()));
    }
}
