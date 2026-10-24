<?php

declare(strict_types=1);

namespace App\Services\Payment\Traits;

use App\Enums\OrderStatusEnum;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlowOrderType;
use App\Models\Order;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/payment-callback-common-checks
 */
trait DepositCallbackTrait
{
    /**
     * 此 Trait 需要使用的方法（由 DriverTrait 提供）
     *
     * @see DriverTrait::getDriverName()
     */
    abstract protected function getDriverName(): string;

    /**
     * 此 Trait 需要使用的屬性
     *
     * AbstractMethod 已提供預設值：
     * - $notifySuccessText = 'success'
     * - $notifyFailText = 'fail'
     *
     * 各金流商可在自己的 Trait 或 Callback 類中覆蓋這些值
     *
     * @see AbstractMethod::$notifySuccessText
     * @see AbstractMethod::$notifyFailText
     */

    /**
     * 查詢並鎖定代收訂單
     *
     * 🔴 **訂單必須屬於這次回調的渠道**（工單 target/tickets#4449 需求 4）。
     * 回調網址帶的 `agent_cash_flow_id` 決定了用哪個商戶的憑證與設定來處理，
     * 不比對的話，A 金流商的回調可以拿 B 金流商的訂單號來結算——攻擊者只要有任何一家
     * 無驗簽金流商的回調網址，就能結算其他家建立的訂單。
     *
     * 渠道不符時回傳 null，與「查無訂單」走同一條路（呼叫端的 checkDepositOrderStatus()
     * 會拋 DepositOrderException，各家以自己與三方約定的失敗文本回覆）；真正的原因另外記一行
     * error，不靠回傳值區分，這樣 33 家呼叫端都不必改。
     *
     * @param  string  $orderNo  訂單號
     */
    protected function findAndLockDepositOrder(string $orderNo): ?Order
    {
        $order = Order::where('order_no', $orderNo)->lockForUpdate()->first();

        if (!$order) {
            Log::warning($this->getDriverName() . ' 訂單不存在 #' . $orderNo);

            return null;
        }

        if (!$this->orderBelongsToCallbackChannel($order)) {
            return null;
        }

        // 加入訂單資訊到日誌上下文，方便查詢日誌
        Log::withContext([
            'order' => [
                'order_no' => $order->order_no,
                'third_party_order_no' => $order->third_party_order_no,
                'cash_flow_id' => $order->cash_flow_id,
                'agent_cash_flow_id' => $order->agent_cash_flow_id,
                'currency_id' => $order->currency_id,
            ],
        ]);

        return $order;
    }

    /**
     * 訂單是不是屬於這次回調網址上的那家金流商。
     *
     * 比對的是 `cash_flow_id`（金流商層級），經 `orders.agent_cash_flow_id → agent_cash_flows.cash_flow_id`
     * 取得，**不是** `agent_cash_flow_id`（商戶層級）：
     * - 回調網址常由金流商固定寫在商戶後台，我方重建 ACF（換費率／換商戶號）後後台網址沒跟著改，
     *   回調就會打到舊 ACF。2026-10-12 TPower FPay 實例：IDR 代收訂單在 271、回調打 263，
     *   兩筆 ACF 是同一組商戶憑證，比 ACF 會把真回調全數擋掉。
     * - 禁止直接讀 `orders.cash_flow_id`：代收建單路徑從沒寫過這個欄位（見 OrderService::createOrder），
     *   永遠是 NULL。
     *
     * 守的仍是「A 金流商的回調不能結算 B 金流商的訂單」（工單 target/tickets#4449 需求 4）：攻擊者
     * 拿任何一家無驗簽金流商的回調網址，結算不了別家建立的訂單。
     *
     * 讀不到 `$this->agentCashFlow` 或訂單的 ACF 時一律視為不符（fail-closed）：前者代表 Callback
     * 類別的 constructor 沒把渠道存進屬性，後者代表訂單掛的 ACF 已不存在，都不能因為讀不到就放行。
     */
    protected function orderBelongsToCallbackChannel(Order $order): bool
    {
        if (!isset($this->agentCashFlow)) {
            Log::error($this->getDriverName() . ' deposit callback rejected: provider not wired', [
                'order_no' => $order->order_no,
                'hint' => 'DepositCallback 的 constructor 必須寫 $this->agentCashFlow = $agentCashFlow;',
            ]);

            return false;
        }

        $orderCashFlowId = $order->agentCashFlow?->cash_flow_id;

        if ($orderCashFlowId !== null && (int) $orderCashFlowId === (int) $this->agentCashFlow->cash_flow_id) {
            return true;
        }

        // 正常流程不可能發生：回調網址上的金流商與訂單的金流商對不起來，代表有人拿這個回調網址
        // 去結算別家金流商建立的訂單。發 TG 告警而不只是寫 log——這是攻擊訊號，不是維運雜訊。
        $context = [
            'order_no' => $order->order_no,
            'callbackAgentCashFlowId' => (int) $this->agentCashFlow->id,
            'orderAgentCashFlowId' => (int) $order->agent_cash_flow_id,
            'callbackCashFlowId' => (int) $this->agentCashFlow->cash_flow_id,
            'orderCashFlowId' => $orderCashFlowId === null ? null : (int) $orderCashFlowId,
        ];

        Log::error($this->getDriverName() . ' deposit callback rejected: order belongs to another payment channel', $context);

        BotNotify::channel('warn')->send(sprintf(
            '[代收回調遭拒：訂單不屬於此渠道]%s%s%s%s',
            PHP_EOL,
            $this->getDriverName(),
            PHP_EOL,
            Common::safeJsonEncode($context)
        ));

        return false;
    }

    /**
     * 檢查代收訂單狀態
     *
     * 統一處理：訂單不存在、訂單已處理的情況
     *
     * 規則：只有 CREATED (0) 狀態的訂單才允許被回調修改。
     * 其他任何狀態（SUCCESS、FAILED、DEPOSIT_FINISHED 等）均視為終態，
     * 拋出 code=200 讓支付商收到成功響應以停止重試。
     *
     * @throws DepositOrderException
     */
    protected function checkDepositOrderStatus(?Order $order): void
    {
        // 查無訂單，返回失敗
        if (!$order) {
            throw new DepositOrderException('Order not found', 400);
        }

        // 非初始狀態 (status != 0) 的訂單不再接受回調修改
        if ($order->status !== OrderStatusEnum::CREATED->value) {
            throw new DepositOrderException('Order already processed', 200);
        }
    }

    /**
     * 處理代收訂單狀態異常
     *
     * 統一處理：根據異常代碼返回對應響應
     * - code 200：訂單已成功處理（冪等），返回成功響應
     * - 其他：返回失敗響應並記錄警告日誌
     */
    protected function handleDepositOrderException(
        DepositOrderException $exception,
        ?Order $order = null
    ): DepositCallbackResponseData {
        if ($exception->getCode() === 200) {
            return $this->buildDepositSuccessResponse($order);
        }

        Log::warning($this->getDriverName() . ' callback rejected by order status', [
            'orderNo' => $order?->order_no,
            'code' => $exception->getCode(),
            'error' => $exception->getMessage(),
        ]);

        return $this->buildDepositFailResponse(errorMessage: $exception->getMessage(), order: $order);
    }

    /**
     * 更新代收訂單為成功
     *
     * @param  Order  $order  訂單
     * @param  string  $thirdPartyOrderNo  三方訂單號
     * @param  array  $callbackData  回調數據
     * @param  float|string|null  $amount  回調金額（用於金額驗證）
     * @param  float|string|null  $fee  手續費（若未提供則自動計算）
     */
    protected function updateDepositOrderSuccess(
        Order $order,
        string $thirdPartyOrderNo,
        array $callbackData,
        float|string|null $amount = null,
        float|string|null $fee = null
    ): void {
        $orderStatus = OrderStatusEnum::SUCCESS->value;

        // 檢查金額若不相符，需標記人工確認
        if ($amount !== null && Common::numCompare($order->amount, $amount, 2) !== 0) {
            $orderStatus = OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value;

            Log::warning($this->getDriverName() . ' callback amount mismatch', [
                'orderNo' => $order->order_no,
                'orderId' => $order->id,
                'orderAmount' => $order->amount,
                'callbackAmount' => $amount,
            ]);

            BotNotify::channel('warn')->send(sprintf(
                '%s 代收回調金額不符，訂單號：%s，訂單金額：%s，回調金額：%s',
                $this->getDriverName(),
                $order->order_no,
                $order->amount,
                $amount
            ));
        }

        // orders.tax 由創單時依 agent_cash_flows 配置決定，callback 不再覆寫，
        // 避免支付商回傳的手續費污染玩家到帳金額與活動計算。
        // 計算手續費（若未提供）
        // if ($fee === null) {
        //     $fee = $this->calculateDepositTax($order);
        // }

        // 更新訂單
        $order->update([
            'status' => $orderStatus,
            'third_party_order_no' => $thirdPartyOrderNo,
            'remark' => Common::safeJsonEncode($callbackData),
            // 'tax' => $fee,
        ]);
    }

    /**
     * 更新代收訂單為失敗
     *
     * @param  Order  $order  訂單
     * @param  string  $thirdPartyOrderNo  三方訂單號
     * @param  array  $callbackData  回調數據
     */
    protected function updateDepositOrderFailed(
        Order $order,
        string $thirdPartyOrderNo,
        array $callbackData
    ): void {
        $order->update([
            'status' => OrderStatusEnum::FAILED->value,
            'third_party_order_no' => $thirdPartyOrderNo,
            'remark' => Common::safeJsonEncode($callbackData),
        ]);
    }

    /**
     * 建立代收通用成功響應
     *
     * @param  Order|null  $order  代收訂單（可選）
     */
    protected function buildDepositSuccessResponse(?Order $order = null): DepositCallbackResponseData
    {
        return DepositCallbackResponseData::from([
            'response' => $this->notifySuccessText,
            'order' => $order,
        ]);
    }

    /**
     * 建立代收通用失敗響應
     *
     * @param  string|null  $errorMessage  響應中附帶的錯誤訊息，同時作為 Log::error 的預設訊息
     * @param  string|null  $logMessage  自訂 Log::error 訊息；為 null 時沿用 $errorMessage；為空字串時不記錄
     * @param  array<string, mixed>  $logContext  Log::error 附帶的 context
     * @param  Order|null  $order  代收訂單（可選）
     */
    protected function buildDepositFailResponse(
        ?string $errorMessage = null,
        ?string $logMessage = null,
        array $logContext = [],
        ?Order $order = null
    ): DepositCallbackResponseData {
        $resolvedLog = $logMessage ?? $errorMessage;

        if ($resolvedLog !== null && $resolvedLog !== '') {
            Log::error($this->getDriverName() . ' ' . $resolvedLog, $logContext);
        }

        return DepositCallbackResponseData::from([
            'response' => $this->notifyFailText,
            'errorMessage' => $errorMessage,
            'order' => $order,
        ]);
    }

    // orders.tax 由創單時依 agent_cash_flows 配置決定，callback 不再覆寫，
    // 此方法已停用，保留註解以便回溯。
    // /**
    //  * 計算代收訂單手續費
    //  *
    //  * 根據 AgentCashFlowOrderType 配置計算手續費
    //  * 手續費 = 固定手續費 + (訂單金額 × 手續費百分比)
    //  *
    //  * @param  Order  $order  訂單
    //  * @return float|string 手續費金額
    //  */
    // private function calculateDepositTax(Order $order): float|string
    // {
    //     $agentCashFlowOrderType = AgentCashFlowOrderType::query()
    //         ->where('agent_cash_flow_id', $order->agent_cash_flow_id)
    //         ->where('order_type_id', $order->order_type_id)
    //         ->first();
    //
    //     if (!$agentCashFlowOrderType) {
    //         Log::warning($this->getDriverName() . ' AgentCashFlowOrderType not found, tax set to 0', [
    //             'orderNo' => $order->order_no,
    //             'agent_cash_flow_id' => $order->agent_cash_flow_id,
    //             'order_type_id' => $order->order_type_id,
    //         ]);
    //
    //         return 0;
    //     }
    //
    //     // 固定手續費
    //     $fixedTax = $agentCashFlowOrderType->tax ?? 0;
    //
    //     // 百分比手續費
    //     $percentageTax = Common::numMul(
    //         $order->amount,
    //         $agentCashFlowOrderType->tax_percentage ?? 0,
    //         4
    //     );
    //
    //     // 總手續費 = 固定手續費 + 百分比手續費
    //     $totalTax = Common::numAdd($fixedTax, $percentageTax, 2);
    //
    //     return $totalTax;
    // }
}
