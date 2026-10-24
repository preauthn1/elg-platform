<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\CashFlow;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\External\SingleWallets\BaseController;
use App\Models\AgentCashFlow;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * 三方金流通知回調訂單狀態與上分
 */
class GetBalanceController extends BaseController
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * 三方金流回調通知
     */
    public function __invoke(Request $request, int $agentCashFlowId = 0)
    {
        if (!$agentCashFlowId) {
            throw new ApiException('Unknow payment provider');
        }

        $agentCashFlow = AgentCashFlow::with(['cashFlow'])->find($agentCashFlowId);

        if (!$agentCashFlow) {
            throw new ApiException('Payment provider not found', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        // 調用三方 Driver：未特殊處理的例外交給 bootstrap/app.php 的 render()/report() 鏈路
        $provider = $this->paymentService->getProvider($agentCashFlow);
        $result = $provider->balance($request);

        // 依據三方規範，返回回調成功格式（使用 provider 指定的 HTTP status code）
        if (!is_array($result) || !isset($result['balance'])) {
            throw new ApiException('Invalid balance response from provider ' . $agentCashFlow->name);
        }

        // 返回字串
        return response()->json($result);
    }
}
