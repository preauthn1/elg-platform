<?php

declare(strict_types=1);

namespace App\Http\Middleware\Agent;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentOperateGeneralLog;
use App\Services\Agent\AgentOperateLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 把代理後台的請求寫進 agent_operate_general_logs（誰、IP、接口、參數），照 API_Agent 的
 * AgentOperationLogsMiddleware：處理前就寫，請求失敗也留紀錄（target/tickets#4422）。
 *
 * 只掛在從 API_Agent 搬過來、原本就有寫這張表的接口上；API_Agent 排除的接口（如選單樹 agentMenu/list）這裡也不掛。
 * 必須排在 AuthAgent 之後（要從 Context 取操作者）。寫入失敗只記 log、不擋請求；登入憑證不入紀錄。
 */
class LogAgentOperation
{
    public function __construct(
        private readonly AgentOperateLogService $agentOperateLogService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Agent|null $agent */
        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);

        if ($agent !== null) {
            try {
                AgentOperateGeneralLog::query()->create([
                    'operator_id' => $agent->id,
                    'page_path' => $request->path(),
                    'request_method' => $request->method(),
                    'ip' => Common::getIp(),
                    'content' => $this->agentOperateLogService->stripCredentials(Common::maskCredentials($request->all())),
                ]);
            } catch (Throwable $e) {
                Log::warning('agent_operate_general_logs 寫入失敗', [
                    'path' => $request->path(),
                    'operator_id' => $agent->id,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }
        }

        return $next($request);
    }
}
