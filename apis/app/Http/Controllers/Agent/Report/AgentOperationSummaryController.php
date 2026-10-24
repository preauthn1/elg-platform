<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent\Report;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Report\AgentOperationSummaryRequest;
use App\Services\Agent\Report\AgentOperationSummaryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * SR28 代理營運彙總表（target/tickets#4362）。
 */
#[Group('報表 (SR28 代理營運彙總表)')]
class AgentOperationSummaryController extends Controller
{
    public function __construct(
        private readonly AgentOperationSummaryService $service,
    ) {}

    /**
     * 彙總列表
     *
     * 第一列是查詢代理本人（只算直屬會員），其餘每列是一個直屬下級代理（含整條下線）；
     * `summary` 為整條線的合計（不限當頁），上方卡片與表底合計列都用它。
     * 指定的代理帳號不在登入者可視範疇內時回「查無此代理」。
     */
    public function list(AgentOperationSummaryRequest $request): JsonResponse
    {
        $agent = $this->getAgentContext()->agent;
        $input = $request->validated();

        $target = $this->service->resolveTarget($agent, $input['agent_username'] ?? null);
        if ($target === null) {
            return ApiResponse::error('查無此代理');
        }

        return ApiResponse::success($this->service->list($agent, $target, $input));
    }
}
