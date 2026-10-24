<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\TwoFactor\DisableRequest;
use App\Http\Requests\Agent\TwoFactor\OtpRequest;
use App\Http\Requests\Agent\TwoFactor\ResetRequest;
use App\Http\Requests\Agent\TwoFactor\VerifyOtpRequest;
use App\Services\Agent\AgentTwoFactorService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 代理 Google 2FA（target/tickets#4518 自 API_Agent 2fa/* 搬入；規則見 AgentTwoFactorService）。
 *
 * 成功回應的 data.relogin_required：本人變更自己的 2FA 時為 true，前端跳「設定已更新，請重新登入」後導回登入頁；
 * 業務失敗回 HTTP 200＋API_Agent 相容碼（TwoFactorResultCodeEnum），前端彈窗就地顯示。
 */
#[Group('2fa (Google 2FA)')]
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly AgentTwoFactorService $service,
    ) {}

    /** 本人的 QRCode 與金鑰；已開啟時兩者皆為空字串 */
    public function image(): JsonResponse
    {
        return ApiResponse::success($this->service->image($this->getAgentContext()->agent));
    }

    /** 驗證自己或範疇內代理的驗證碼（登入二階段與開啟前測試用） */
    public function auth(VerifyOtpRequest $request): JsonResponse
    {
        $this->service->auth($this->getAgentContext()->agent, (int) $request->agent_id, (string) $request->secret);

        return ApiResponse::success();
    }

    /** 本人開啟 */
    public function enable(OtpRequest $request): JsonResponse
    {
        $this->service->enable($this->getAgentContext()->agent, (string) $request->secret);

        return ApiResponse::success(['relogin_required' => true]);
    }

    /** 關閉自己（不帶 agent_id）或範疇內的代理 */
    public function disable(DisableRequest $request): JsonResponse
    {
        $isSelf = $this->service->disable(
            $this->getAgentContext()->agent,
            $request->filled('agent_id') ? (int) $request->agent_id : null,
            (string) $request->secret,
        );

        return ApiResponse::success(['relogin_required' => $isSelf]);
    }

    /** 重置他人（權限碼由路由把關） */
    public function reset(ResetRequest $request): JsonResponse
    {
        $this->service->reset($this->getAgentContext()->agent, (int) $request->agent_id, (string) $request->secret);

        return ApiResponse::success(['relogin_required' => false]);
    }
}
