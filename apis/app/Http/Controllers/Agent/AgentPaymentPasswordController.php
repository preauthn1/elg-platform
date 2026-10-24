<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\PaymentPassword\AgentPaymentPasswordVerifyRequest;
use App\Services\Agent\AgentPaymentPasswordService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 驗證操作者自己的支付密碼（target/tickets#4438）：會員與代理的開洗分、錢包操作彈窗送出前呼叫。
 */
#[Group('agent (支付密碼)')]
class AgentPaymentPasswordController extends Controller
{
    public function __construct(
        private readonly AgentPaymentPasswordService $paymentPasswordService,
    ) {}

    /**
     * 驗證支付密碼
     *
     * 只驗登入者本人，不收 agent_id；錯誤回「密碼錯誤」。
     */
    public function verify(AgentPaymentPasswordVerifyRequest $request): JsonResponse
    {
        $ok = $this->paymentPasswordService->verify(
            $this->getAgentContext()->agent,
            (string) $request->input('payment_password'),
        );

        return $ok ? ApiResponse::success() : ApiResponse::error(__('error_message.90052'));
    }
}
