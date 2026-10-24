<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\OperateLog\ExportRequest;
use App\Services\Agent\AgentOperateLogService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('operate-logs (代理後台操作稽核紀錄)')]
class AgentOperateLogController extends Controller
{
    public function __construct(
        private AgentOperateLogService $agentOperateLogService,
    ) {}

    /**
     * 記錄後台 Excel 匯出稽核紀錄
     *
     * 呼叫端採 fail-closed：本 API 未回 code 200 時不得輸出 Excel 檔案。
     */
    public function export(ExportRequest $request): JsonResponse
    {
        $input = $request->validated();
        $context = $this->getAgentContext();
        $agent = $context->agent;

        if (!$agent) {
            throw new ApiException('Agent not found');
        }

        $this->agentOperateLogService->logExport(
            agentId: $agent->id,
            // 不用 $context->ip：那是 DetectIp 以 Common::getIp() 取的，
            // 代理後台走 WEB curl 代打時會拿到 WEB 主機出口 IP 而非操作者 IP
            ip: Common::getAgentClientIp(),
            action: $input['action'],
            menuName: $input['menu_name'] ?? '',
            menuUrl: $input['menu_url'] ?? '',
            conditions: $input['conditions'] ?? [],
            recordCount: (int) $input['record_count'],
        );

        return ApiResponse::success();
    }
}
