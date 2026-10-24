<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\IpRestrictionBlockItemEnum;
use App\Enums\MemberStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Services\AgentAncestorStatusGuard;
use App\Services\IpRestrictionGuard;
use App\Services\MemberRestrictionGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthMember
{
    public function __construct(
        private MemberRestrictionGuard $memberRestrictionGuard,
        private IpRestrictionGuard $ipRestrictionGuard,
        private AgentAncestorStatusGuard $agentAncestorStatusGuard,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws ApiException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = [REDACTED];

        // 排除少數可以不需要 getUserContext 的路由
        $excludedRoutes = [
            'frontend/api/member/logout',
        ];

        if (!$token && in_array($request->path(), $excludedRoutes)) {
            return $next($request);
        }

        // 風控IP限制(4096)：來源IP有勾選「登入」的啟用規則 → 踢線（通知前端登出）。
        // 不依賴 token/member,放最前面;不累計 repeat_count(僅實際嘗試才累計),避免灌水。
        // code 必須維持 HTTP_REDIRECT_LOGOUT(319) 前端才會登出,錯誤碼放在訊息文案內
        if ($this->ipRestrictionGuard->isBlocked(Common::getIp(), IpRestrictionBlockItemEnum::LOGIN)) {
            return ApiResponse::error(
                message: IpRestrictionBlockItemEnum::LOGIN->message(),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        if (!$token) {
            // 通知前端登出
            return ApiResponse::error(
                message: __('error_message.9998'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        // 先從快取取 accessToken
        $cacheKey = CacheKeys::memberAccessToken($token);
        $cachedData = Cache::get($cacheKey);

        $tokenMemberId = null;

        // 如果快取存在且包含過期時間,檢查是否過期
        if ($cachedData) {
            if (is_array($cachedData) && isset($cachedData['expire_at'])) {
                if ($cachedData['expire_at'] > time()) {
                    $tokenMemberId = $cachedData['member_id'];
                } else {
                    // 過期則刪除快取
                    Cache::forget($cacheKey);
                }
            }
        }

        // 快取不存在或已過期,查詢資料庫
        if (!$tokenMemberId) {
            $accessToken = [REDACTED]
                ->where('token', $token)
                ->where('expire_date', '>', date('Y-m-d H:i:s'))
                ->first();

            if ($accessToken) {
                $tokenMemberId = $accessToken->member_id;
                // 計算剩餘秒數
                $expireAt = strtotime($accessToken->expire_date);
                $ttl = max(1, $expireAt - time());

                // 快取包含 member_id 和過期時間
                Cache::put($cacheKey, [
                    'member_id' => $tokenMemberId,
                    'expire_at' => $expireAt,
                ], $ttl);
            }
        }

        if (!$tokenMemberId) {
            // 通知前端登出
            return ApiResponse::error(
                message: __('error_message.9998'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        $member = Member::with('agent')->find($tokenMemberId);

        if (!$member) {
            throw new ApiException(__('error_message.90051'));
        }

        if ($member->status != MemberStatusEnum::VERIFIED->value) {
            throw new ApiException(__('error_message.90062'));
        }

        // 風控會員限制:停用(禁止登入)。每請求即時檢查,已登入者下一個請求即被強制登出(自然踢線,毋須 revoke token)
        if ($this->memberRestrictionGuard->isLoginBlocked($member)) {
            return ApiResponse::error(
                message: __('error_message.member_restriction.login_blocked'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        // 4130:上級代理停用(含直屬代理)→下級會員每請求即時檢查,同上自然踢線
        if ($this->agentAncestorStatusGuard->isMemberAncestorDisabled($member->agent)) {
            return ApiResponse::error(
                message: __('error_message.agent_status.ancestor_disabled'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        Context::addHidden(ContextKeyEnum::TOKEN->value, $token);
        Context::addHidden(ContextKeyEnum::MEMBER->value, $member->makeHidden('agent'));
        Context::addHidden(ContextKeyEnum::AGENT->value, $member->agent);

        // Log::withContext(['member_id' => $member->id]);

        Auth::setUser($member);

        return $next($request);
    }
}
