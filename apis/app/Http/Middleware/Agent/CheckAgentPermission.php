<?php

declare(strict_types=1);

namespace App\Http\Middleware\Agent;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\ApiResponse;
use App\Models\Agent;
use App\Services\Agent\AgentMenuPermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * 代理後台功能級授權（稽核 #13 / OWASP API5）。
 *
 * `AuthAgent` 只驗 token 有效性，任何有效 agent token 都能呼叫全部端點；代理後台的
 * 選單權限只擋在頁面層（WEB_AgentBackend 各頁的 `$check_auth[...]`），發請求的 ajax
 * 層與本專案都沒擋，繞過 UI 直接打即可越權。此中間件把同一套選單權限補在 API 層。
 *
 * 用法：`CheckAgentPermission::class . ':event_v2_wallet'`，多個 code 以逗號分隔表示
 * 「任一即可」（同一支 API 被多個頁面共用時）。
 */
class CheckAgentPermission
{
    public function __construct(
        protected AgentMenuPermissionService $agentMenuPermissionService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$menuCodes  `agent_menus.code`，任一持有即放行
     */
    public function handle(Request $request, Closure $next, string ...$menuCodes): Response
    {
        /** @var Agent|null $agent */
        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);

        if ($agent === null || !$this->agentMenuPermissionService->hasAny($agent, $menuCodes)) {
            return ApiResponse::error(__('error_message.9003')); // 權限不足
        }

        return $next($request);
    }
}
