<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\CashFlow;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Http\Controllers\External\SingleWallets\BaseController;
use App\Models\AgentCashFlow;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 三方反查代付訂單狀態
 */
class WithdrawalQueryController extends BaseController
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * 三方反查代付訂單
     */
    public function __invoke(Request $request, int $agentCashFlowId = 0)
    {
        if (!$agentCashFlowId) {
            throw new ApiException('Unknown payment provider');
        }

        $agentCashFlow = AgentCashFlow::with(['cashFlow'])->find($agentCashFlowId);

        if (!$agentCashFlow) {
            throw new ApiException('Payment provider not found', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        // 記錄收到的三方查詢請求（遮硬憑證如 PIN/卡號，保留其餘欄位供除錯／驗簽）
        Log::info(sprintf('[%s] Withdrawal query received', $agentCashFlow->name), [
            'agent_cash_flow_id' => $agentCashFlowId,
            'agent_cash_flow' => $agentCashFlow->only(['id', 'name']),
            'url' => $request->fullUrl(),
            'request' => Common::maskCredentials($request->all()),
        ]);

        // 確認 cashFlow.type 為 3 (線上代付)
        if (!$agentCashFlow->cashFlow || $agentCashFlow->cashFlow->type !== 3) {
            Log::error('Invalid cash flow type for withdrawal query', [
                'agent_cash_flow_id' => $agentCashFlowId,
                'cash_flow_type' => $agentCashFlow->cashFlow->type ?? null,
            ]);
            throw new ApiException('Invalid cash flow type: Expected online withdrawal', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        // 調用三方 Driver 的查詢方法：未特殊處理的例外交給 bootstrap/app.php 的 render()/report() 鏈路
        $provider = $this->paymentService->getProvider($agentCashFlow);
        $result = $provider->withdrawalQuery($request);

        if (isset($result['errorMessage']) && $result['errorMessage']) {
            Log::error($result['errorMessage'], [
                'request' => Common::maskCredentials($request->all()),
                'agent_cash_flow_id' => $agentCashFlowId,
            ]);
        }

        // 依據三方規範，返回查詢結果格式
        if (is_array($result)) {
            return response()->json($result);
        }

        // 返回字串
        return response($result);
    }
}
