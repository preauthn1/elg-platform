<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CashFlow\CashFlowScopeEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\CashFlow\DepositRequest;
use App\Http\Requests\Agent\Deposit\RequestLogsRequest;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\AgentCashFlowListService;
use App\Services\AgentService;
use App\Services\OrderService;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\OrderRequestLogDisplayService;
use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Support\Facades\Log;

#[Group('deposit (代理支付渠道)')]
/**
 * @knowledge rules/agent-self-deposit
 */
class DepositController extends Controller
{
    public function __construct(
        protected AgentCashFlowListService $agentCashFlowListService,
        protected AgentService $agentService,
        protected OrderService $orderService,
        protected PaymentService $paymentService,
        protected OrderRequestLogDisplayService $orderRequestLogDisplayService,
    ) {}

    /**
     * 取得支付渠道列表
     */
    public function channels()
    {
        $agentData = $this->getAgentContext();
        $agent = $agentData->agent;
        $languageId = $agentData->languageId;

        $data = $this->agentCashFlowListService->getChannels(
            agentGroupId: $agent->cash_flow_group_id,
            languageId: $languageId,
        );

        return ApiResponse::success($data);
    }

    /**
     * 取得儲值訂單的三方往返紀錄（供後台訂單詳情顯示）
     *
     * 🔴 回傳內容已由 {@see OrderRequestLogDisplayService} 以 provider 白名單過濾過：
     * header 一律不回、未宣告白名單的 provider 回空內容。**禁止在此直接回 OrderRequestLog**
     * ——那張表存的是未遮罩原文，含明文商戶金鑰。
     */
    public function requestLogs(RequestLogsRequest $request)
    {
        $order = Order::with('member')->find((int) $request->validated('order_id'));

        if (!$order) {
            return ApiResponse::error('Order not found', 404);
        }

        if (!$this->isOrderInScope($order)) {
            return ApiResponse::error(__('error_message.9003'), 403); // 權限不足
        }

        return ApiResponse::success($this->orderRequestLogDisplayService->forOrder($order));
    }

    /**
     * 訂單是否在此代理的可見範疇內（與 WithdrawalController::isOrderInScope 同一套判定）
     */
    private function isOrderInScope(Order $order): bool
    {
        $ownerAgentId = $order->member_id > 0
            ? (int) ($order->member?->parent ?? 0)
            : (int) $order->agent_id;

        return $this->getAgentContext()->agent->canSeeAgent($ownerAgentId ?: null);
    }

    /**
     * 請求三方代收 (儲值)
     */
    public function deposit(DepositRequest $request)
    {
        // 請求參數
        $input = $request->validated();

        // 上下文
        $agent = $this->getAgentContext()->agent;
        $cashFlowGroupId = $agent->cash_flow_group_id;

        // 代理設定
        $agentSettings = $this->agentService->getAgentSettings($agent);

        // 檢查代理是否允許自助開分/洗分
        $withdrawalEnable = $agentSettings[AgentCustomizedSettingKeyEnum::AGENT_SELF_WITHDRAWAL_ENABLE] ?? 0;
        if (!$withdrawalEnable) {
            throw new ApiException('Agent self cash flow is disabled');
        }

        // 取得代收渠道
        $orderTypeQuery = OrderType::query()->where('id', $input['order_type_id']);
        try {
            $orderType = $orderTypeQuery->firstOrFail();
        } catch (Exception $e) {
            Log::error('找不到訂單類型', [
                'order_type_id' => $input['order_type_id'],
                'sql' => $orderTypeQuery->toRawSql(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // 取得代理金流渠道
        $agentCashFlowQuery = AgentCashFlow::query()
            ->where('agent_group_id', $cashFlowGroupId)
            ->whereHas('orderTypes', function ($query) use ($orderType) {
                $query->where('order_type_id', $orderType->id);
            });
        try {
            $agentCashFlow = $agentCashFlowQuery->firstOrFail();
        } catch (Exception $e) {
            Log::error('找不到對應的代理金流渠道', [
                'cash_flow_group_id' => $cashFlowGroupId,
                'order_type_id' => $input['order_type_id'],
                'sql' => $agentCashFlowQuery->toRawSql(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // 舊系統範疇的通道不受理：新代理後台以同一條分界決定打哪支 API，這裡是後端的最後一道
        if (!CashFlowScopeEnum::isHandledByApis((int) $agentCashFlow->cash_flow_id)) {
            throw new ApiException(CashFlowScopeEnum::notHandledMessage((int) $agentCashFlow->cash_flow_id));
        }

        // 建立代收訂單。部分渠道（例如 TNGPIN 的 PIN 碼儲值）表單沒有金額欄位，
        // 實際金額由三方回調決定，建單時先以 0 帶入，與會員端 Frontend\DepositController 同一規則
        $amount = number_format((float) ($input['amount'] ?? 0), 2, '.', '');

        $order = $this->orderService->createOrder(
            orderType: $orderType,
            agentCashFlow: $agentCashFlow,
            member: $agent,
            amount: $amount,
            extraContent: $request->getExtraFields(),
        );

        if (!$order) {
            throw new ApiException('Failed to create deposit order');
        }

        // 調用三方 Driver 請求代收
        try {
            $provider = $this->paymentService->getProvider($agentCashFlow);

            $params = DepositRequestData::from([
                'member' => $agent,
                'order' => $order,
                'callbackUrl' => Common::depositCallbackUrl($agentCashFlow->id),
                'returnUrl' => $input['return_url'],
                'ip' => Common::getIp(),
            ]);

            /** @var DepositResponseData $result */
            $result = $provider->deposit($params);
        } catch (Exception $e) {
            Log::error('調用三方代收失敗', [
                'agent_id' => $agent->id,
                'cash_flow_group_id' => $cashFlowGroupId,
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'at' => $e->getFile() . ':' . $e->getLine(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        /**
         * ! 前端規則
         * type:0 支付系統忙碌中
         * type:1 跳轉支付頁
         * type:3 顯示回傳的html
         * type:4 顯示成功,回錢包頁
         * type:11 顯示qrcode
         */
        return ApiResponse::success($result->toArray());
    }
}
