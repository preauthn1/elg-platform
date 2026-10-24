<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend;

use App\Enums\General\ContextKeyEnum;
use App\Enums\MemberStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Services\AgentAncestorStatusGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class HasMember
{
    public function __construct(
        private AgentAncestorStatusGuard $agentAncestorStatusGuard,
    ) {}

    /**
     * 雖然不需要驗證 token
     * 但如果有 token 的話則查詢會員與代理商放在上下文
     *
     * @throws ApiException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = [REDACTED];

        if (!$token) {
            return $next($request);
        }

        $accessToken = [REDACTED]'token', $token)
            ->where('expire_date', '>', date('Y-m-d H:i:s'))
            ->first();

        if (!$accessToken) {
            return $next($request);
        }

        $member = Member::with('agent')->find($accessToken->member_id);

        if (!$member) {
            return $next($request);
        }

        if ($member->status != MemberStatusEnum::VERIFIED->value) {
            throw new ApiException(__('error_message.90062'));
        }

        // 4130:上級代理停用(含直屬代理)→下級會員視同未授權(與 AuthMember 的踢線判定一致)
        if ($this->agentAncestorStatusGuard->isMemberAncestorDisabled($member->agent)) {
            throw new ApiException(__('error_message.agent_status.ancestor_disabled'));
        }

        Context::addHidden(ContextKeyEnum::TOKEN->value, $token);
        Context::addHidden(ContextKeyEnum::MEMBER->value, $member->makeHidden('agent'));
        Context::addHidden(ContextKeyEnum::AGENT->value, $member->agent);

        Auth::setUser($member);

        return $next($request);
    }
}
