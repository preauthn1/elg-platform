<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Helpers\Traits\GetContextTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Withdrawal\CheckPayoutChannelsRequest;
use App\Http\Requests\Agent\Withdrawal\FillThirdPartyNoRequest;
use App\Http\Requests\Agent\Withdrawal\MarkPayoutFailedRequest;
use App\Http\Requests\Agent\Withdrawal\PayoutRequest;
use App\Http\Requests\Agent\Withdrawal\RequestLogsRequest;
use App\Models\WithdrawalOrder;
use App\Services\Payment\OrderRequestLogDisplayService;
use App\Services\WithdrawalPayoutManualService;
use App\Services\WithdrawalPayoutService;
use Dedoc\Scramble\Attributes\Group;

#[Group('withdrawal (提款)')]
class WithdrawalController extends Controller
{
    use GetContextTrait;

    public function __construct(
        protected WithdrawalPayoutService $withdrawalPayoutService,
        protected WithdrawalPayoutManualService $withdrawalPayoutManualService,
        protected OrderRequestLogDisplayService $orderRequestLogDisplayService,
    ) {}

    /**
     * 查詢提款訂單可用的代付渠道
     */
    public function channels(CheckPayoutChannelsRequest $request)
    {
        $order = WithdrawalOrder::with(['bank', 'member'])->find((int) $request->validated('order_id'));

        if (!$order) {
            return ApiResponse::error('Order not found', 404);
        }

        if (!$this->isOrderInScope($order)) {
            return ApiResponse::error(__('error_message.9003'), 403); // 權限不足
        }

        $result = $this->withdrawalPayoutService->resolveChannels($order);

        if (is_string($result)) {
            return ApiResponse::error($result, 400);
        }

        return ApiResponse::success($result);
    }

    /**
     * 請求三方代付（target/tickets#4137 事務邊界拆分，業務下沉 WithdrawalPayoutService）
     */
    public function payout(PayoutRequest $request)
    {
        $input = $request->validated();

        if (!$this->isOrderIdInScope((int) $input['order_id'])) {
            return ApiResponse::error(__('error_message.9003'), 403); // 權限不足
        }

        $errorMessage = $this->withdrawalPayoutService->submit(
            orderId: (int) $input['order_id'],
            agentCashFlowId: (int) $input['agent_cash_flow_id'],
        );

        return $errorMessage === null ? ApiResponse::success() : ApiResponse::error($errorMessage);
    }

    /**
     * 人工填寫三方單號（21 → 22）：人工於支付商後台查到有單時使用（target/tickets#4137）
     */
    public function fillThirdPartyNo(FillThirdPartyNoRequest $request)
    {
        $input = $request->validated();

        if (!$this->isOrderIdInScope((int) $input['order_id'])) {
            return ApiResponse::error(__('error_message.9003'), 403); // 權限不足
        }

        $this->withdrawalPayoutManualService->fillThirdPartyOrderNo(
            orderId: (int) $input['order_id'],
            thirdPartyOrderNo: (string) $input['third_party_order_no'],
            operator: $this->getAgentContext()->agent,
            remark: $input['remark'] ?? null,
        );

        return ApiResponse::success();
    }

    /**
     * 人工標記代付失敗（21/22 → 29）：人工於支付商後台確認查無此單時使用（target/tickets#4137）
     */
    public function markFailed(MarkPayoutFailedRequest $request)
    {
        $input = $request->validated();

        if (!$this->isOrderIdInScope((int) $input['order_id'])) {
            return ApiResponse::error(__('error_message.9003'), 403); // 權限不足
        }

        $this->withdrawalPayoutManualService->markFailed(
            orderId: (int) $input['order_id'],
            operator: $this->getAgentContext()->agent,
            remark: $input['remark'] ?? null,
        );

        return ApiResponse::success();
    }

    /**
     * 取得提款訂單的三方往返紀錄（供後台訂單詳情顯示，工單 target/tickets#4449）
     *
     * 🔴 回傳內容已由 {@see OrderRequestLogDisplayService} 整理成純量，往返原文一律不回
     * ——那張表存的是未遮罩原文，含明文商戶金鑰。**禁止在此直接回 OrderRequestLog**。
     */
    public function requestLogs(RequestLogsRequest $request)
    {
        $order = WithdrawalOrder::with('member')->find((int) $request->validated('order_id'));

        if (!$order) {
            return ApiResponse::error('Order not found', 404);
        }

        if (!$this->isOrderInScope($order)) {
            return ApiResponse::error(__('error_message.9003'), 403); // 權限不足
        }

        return ApiResponse::success($this->orderRequestLogDisplayService->forWithdrawalOrder($order));
    }

    /**
     * 訂單是否落在呼叫代理的可視範疇內（target/tickets#4140 稽核 #9）。
     *
     * 歸屬判定：`member_id > 0` 為會員提款訂單、歸會員的上層代理（`members.parent`）；
     * `member_id = 0` 為代理提款訂單、歸下單代理（`agent_id`）。admin 與 admin 子帳號
     * 不受限；歸屬代理取不到時對非 admin 一律 fail-closed。
     */
    private function isOrderInScope(WithdrawalOrder $order): bool
    {
        $ownerAgentId = $order->member_id > 0
            ? (int) ($order->member?->parent ?? 0)
            : (int) $order->agent_id;

        return $this->getAgentContext()->agent->canSeeAgent($ownerAgentId ?: null);
    }

    /**
     * 以訂單編號做範疇檢查，供業務已下沉 Service 的端點使用。
     *
     * 查無訂單一律視為通過，交由下游流程回報 not found——與 channels() 先回 404
     * 再驗範疇的順序一致，不以 9003 掩蓋「訂單不存在」。
     */
    private function isOrderIdInScope(int $orderId): bool
    {
        $order = WithdrawalOrder::with('member')->find($orderId);

        return $order === null || $this->isOrderInScope($order);
    }
}
