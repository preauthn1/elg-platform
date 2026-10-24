<?php

declare(strict_types=1);

namespace App\Http\Middleware\Agent;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\ApiResponse;
use App\Models\Agent;
use App\Services\Agent\AgentPermissionScopeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * 會員詳情抽屜的身份門檻（target/tickets#4438 第 7 項）：只有系統管理者、總代理（含各自子帳號）、客服能用抽屜，
 * 其他身份前端不給入口，後端也在這裡擋，避免直接打接口。判斷規則在 AgentPermissionScopeService::canUseMemberDrawer()。
 */
class CheckMemberDrawerAccess
{
    public function __construct(
        protected AgentPermissionScopeService $scopeService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Agent|null $agent */
        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);

        if ($agent === null || !$this->scopeService->canUseMemberDrawer($agent)) {
            return ApiResponse::error(__('error_message.9003')); // 權限不足
        }

        return $next($request);
    }
}
