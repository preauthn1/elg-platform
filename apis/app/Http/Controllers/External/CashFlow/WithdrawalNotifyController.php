<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\CashFlow;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\BaseController;
use App\Models\AgentCashFlow;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 三方金流通知回調訂單狀態與上分
 *
 * @knowledge rules/payment-callback-common-checks
 */
class WithdrawalNotifyController extends BaseController
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

        // 記錄收到的三方回調請求（遮硬憑證如 PIN/卡號，保留其餘欄位供除錯／驗簽）
        Log::info(sprintf('[%s] Withdrawal callback received', $agentCashFlow->name), [
            'agent_cash_flow_id' => $agentCashFlowId,
            'agent_cash_flow' => $agentCashFlow->only(['id', 'name']),
            'url' => $request->fullUrl(),
            'request' => Common::maskCredentials($request->all()),
        ]);

        // 應確認 cashFlow.type 為 3 (線上代付)
        if (!$agentCashFlow->cashFlow || $agentCashFlow->cashFlow->type !== 3) {
            Log::error('Invalid cash flow type for withdrawal callback', [
                'agent_cash_flow_id' => $agentCashFlowId,
                'cash_flow_type' => $agentCashFlow->cashFlow->type ?? null,
            ]);
            throw new ApiException('Invalid cash flow type: Expected online withdrawal', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        // 調用三方 Driver：未特殊處理的例外交給 bootstrap/app.php 的 render()/report() 鏈路
        $provider = $this->paymentService->getProvider($agentCashFlow);
        /** @var WithdrawalCallbackResponseData $result */
        $result = $provider->withdrawalCallback($request);

        if ($result->errorMessage) {
            Log::error($result->errorMessage, [
                'request' => Common::maskCredentials($request->all()),
                'agent_cash_flow_id' => $agentCashFlowId,
            ]);
        }

        // 依據三方規範，返回回調成功格式（使用 provider 指定的 HTTP status code）
        if (is_array($result->response)) {
            // 返回 json
            return response()->json($result->response, $result->httpStatusCode);
        }

        // 返回字串
        return response($result->response, $result->httpStatusCode);
    }
}
