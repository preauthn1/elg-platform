<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Report\AgentGameDetailRequest;
use App\Services\Agent\AgentGameDetailService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * SR29 代理遊戲明細表（target/tickets#4318）。
 *
 * 需求文件標題寫的是「SR26 代理遊戲明細表」，但 SR26／SR27 兩個編號都已被既有報表
 * 佔用（SR26 佔成錢包紀錄明細表、SR27 獎勵發放明細表），經 PM 確認改掛 SR29。
 */
#[Group('報表 (SR29 代理遊戲明細表)')]
class AgentGameDetailController extends Controller
{
    public function __construct(
        private readonly AgentGameDetailService $service,
    ) {}

    /**
     * 明細列表（主表與所有下鑽層共用）
     *
     * 不帶 `vendor_id`／`game_type_id` 回第 0 層主表（遊戲商 × 遊戲類別）；
     * 帶了就回下鑽層——列出 `agent_id`（預設登入者）的直屬下級，直屬會員為葉節點、
     * 下級代理的數字含其整條下線。每一層都附合計列，且合計等於上一層被點的那一列。
     *
     * 需求文件③：每點一層才打一次，只取該層的直屬下級，不預抓整棵樹；往回跳由前端
     * 用快取，不重打。
     */
    public function list(AgentGameDetailRequest $request): JsonResponse
    {
        $context = $this->getAgentContext();

        return ApiResponse::success($this->service->list(
            $context->agent,
            $request->validated(),
            // 遊戲類別名稱依代理語系取（game_type_names），與 SR27 既有慣例一致
            $context->languageId,
        ));
    }
}
