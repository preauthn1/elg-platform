<?php

declare(strict_types=1);

namespace App\Http\Middleware\Agent;

use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Helpers\ApiResponse;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Services\AgentAncestorStatusGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class AuthAgent
{
    public function __construct(
        private AgentAncestorStatusGuard $agentAncestorStatusGuard,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = [REDACTED];

        if (!$token) {
            // 通知前端登出
            return ApiResponse::error(
                message: __('error_message.9998'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        $accessToken = [REDACTED]'token', $token)
            ->where('expire_date', '>', date('Y-m-d H:i:s'))
            ->first();

        if (!$accessToken) {
            // 通知前端登出
            return ApiResponse::error(
                message: __('error_message.9998'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        $agent = Agent::find($accessToken->agent_id);

        // 4130:代理自身停用→每請求即時檢查,已登入者下一個請求即被強制登出(自然踢線,毋須 revoke token)
        if (!$agent || $agent->status != 1) {
            return ApiResponse::error(
                message: __('error_message.90062'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        // 4130:上級代理停用→下級代理每請求即時檢查,同上自然踢線
        if ($this->agentAncestorStatusGuard->isAgentAncestorDisabled($agent)) {
            return ApiResponse::error(
                message: __('error_message.agent_status.ancestor_disabled'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        // 將 agent 存入 context，供後續中間件使用
        Context::addHidden(ContextKeyEnum::AGENT->value, $agent);
        Context::addHidden(ContextKeyEnum::TOKEN->value, $token);

        return $next($request);
    }
}
