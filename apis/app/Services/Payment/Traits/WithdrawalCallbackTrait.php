<?php

declare(strict_types=1);

namespace App\Services\Payment\Traits;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/payment-callback-common-checks
 * @knowledge rules/payment-cost-summary
 */
trait WithdrawalCallbackTrait
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
     * 查詢並鎖定代付請求記錄
     *
     * @param  string  $transactionNo  交易號（我方訂單號）
     */
    protected function findAndLockPayoutRecord(string $transactionNo): ?WithdrawalPayoutRecord
    {
        $record = WithdrawalPayoutRecord::query()
            ->where('transaction_no', $transactionNo)
            ->lockForUpdate()
            ->first();

        return $this->payoutRecordInCallbackChannel($record);
    }

    /**
     * 查詢並鎖定代付請求記錄（通過三方訂單號）
     *
     * @param  string  $thirdPartyTransactionNo  三方訂單號
     * @param  string  $transactionNo  我方訂單號
     */
    protected function findAndLockPayoutRecordByThirdPartyNo(
        string $thirdPartyTransactionNo,
        string $transactionNo
    ): ?WithdrawalPayoutRecord {
        $record = WithdrawalPayoutRecord::query()
            ->where('third_party_transaction_no', $thirdPartyTransactionNo)
            ->where('transaction_no', $transactionNo)
            ->lockForUpdate()
            ->first();

        return $this->payoutRecordInCallbackChannel($record);
    }

    /**
     * 代付請求必須屬於這次回調網址上的那家金流商（工單 target/tickets#4449 需求 4）。
     *
     * 🔴 **代付的風險比代收高**：代收被偽造是多給點數，代付被偽造的失敗回調會讓還在「代付請求中」
     * 「代付處理中」的提款單先退點給會員，之後三方若真的出款就等於付了兩次。
     *
     * 比對的是 `cash_flow_id`（金流商層級），經 `withdrawal_payout_records.agent_cash_flow_id →
     * agent_cash_flows.cash_flow_id` 取得，**不是** `agent_cash_flow_id`（商戶層級）：回調網址常由
     * 金流商固定寫在商戶後台，我方重建 ACF 後後台網址沒跟著改，回調就會打到舊 ACF。
     * 2026-10-12 TPower FPay 實例：IDR 代付紀錄在 272、回調打 264，VND 代付紀錄在 270、回調打 262。
     *
     * 不符時回傳 null，與「查無紀錄」走同一條路（呼叫端本來就要處理 null），所以各家呼叫端不必改；
     * 真正的原因另外記一行 error 並發 TG 告警——正常流程不可能走到這裡。
     */
    protected function payoutRecordInCallbackChannel(?WithdrawalPayoutRecord $record): ?WithdrawalPayoutRecord
    {
        if ($record === null) {
            return null;
        }

        if (!isset($this->agentCashFlow)) {
            Log::error($this->getDriverName() . ' withdrawal callback rejected: provider not wired', [
                'transaction_no' => $record->transaction_no,
                'hint' => 'WithdrawalCallback 的 constructor 必須寫 $this->agentCashFlow = $agentCashFlow;',
            ]);

            return null;
        }

        // 讀不到紀錄的 ACF（已被刪）一律視為不符（fail-closed）
        $recordCashFlowId = $record->agentCashFlow?->cash_flow_id;

        if ($recordCashFlowId !== null && (int) $recordCashFlowId === (int) $this->agentCashFlow->cash_flow_id) {
            return $record;
        }

        $context = [
            'transaction_no' => $record->transaction_no,
            'callbackAgentCashFlowId' => (int) $this->agentCashFlow->id,
            'recordAgentCashFlowId' => (int) $record->agent_cash_flow_id,
            'callbackCashFlowId' => (int) $this->agentCashFlow->cash_flow_id,
            'recordCashFlowId' => $recordCashFlowId === null ? null : (int) $recordCashFlowId,
        ];

        Log::error($this->getDriverName() . ' withdrawal callback rejected: payout record belongs to another payment channel', $context);

        BotNotify::channel('warn')->send(sprintf(
            '[代付回調遭拒：代付請求不屬於此渠道]%s%s%s%s',
            PHP_EOL,
            $this->getDriverName(),
            PHP_EOL,
            Common::safeJsonEncode($context)
        ));

        return null;
    }

    /**
     * 檢查代付記錄是否已處理（冪等主體 = record 自身，非訂單）
     *
     * 只有 record 自己的 is_success_payout 為 SUCCESS (1) 才視為已處理：
     * - PENDING (0) / REQUEST_FAILED (-1) / THIRD_PARTY_REJECTED (-2) /
     *   CALLBACK_FAILED (-3) 不列入，允許三方補發回調（含失敗→成功翻轉）
     *
     * ⚠️ 不得以「訂單」狀態判定：一訂單多 payout record 時，訂單 24 只代表
     * 其中一筆成功；若以訂單判冪等，其餘 record 的成功回調會被誤判已處理而
     * 靜默吞掉——等於丟失「另一筆真實出款」的唯一通知（2174206 事故，缺陷 B）。
     * 這類回調現改由 checkWithdrawalOrderStatus() 拒絕並發告警，交人工對帳。
     *
     * @return bool true: 已處理（不可逆），false: 可繼續處理
     */
    protected function isPayoutRecordProcessed(?WithdrawalPayoutRecord $payoutRecord): bool
    {
        if (!$payoutRecord) {
            return false;
        }

        return $payoutRecord->is_success_payout === WithdrawalPayoutStatusEnum::SUCCESS;
    }

    /**
     * 查詢並鎖定提款訂單（通過 payout record）
     */
    protected function findAndLockWithdrawalOrderByPayoutRecord(WithdrawalPayoutRecord $payoutRecord): ?WithdrawalOrder
    {
        return WithdrawalOrder::where('id', $payoutRecord->withdrawal_order_id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * 更新代付請求記錄
     *
     * @param  array  $callbackData  回調數據
     * @param  bool  $isSuccess  是否成功
     * @param  float|string|null  $resultAmount  結果金額
     * @param  int|string|null  $resultCode  結果代碼
     * @param  string|null  $transactionTime  交易時間
     * @param  string|null  $sign  簽名
     */
    protected function updatePayoutRecord(
        WithdrawalPayoutRecord $payoutRecord,
        array $callbackData,
        bool $isSuccess,
        float|string|null $resultAmount = null,
        int|string|null $resultCode = null,
        ?string $transactionTime = null,
        ?string $sign = null
    ): void {
        $now = now()->toDateTimeString();

        $updateData = [
            'callback_request_body' => Common::safeJsonEncode($callbackData),
            'callback_time' => $now,
            'is_success_payout' => $isSuccess
                ? WithdrawalPayoutStatusEnum::SUCCESS->value
                : WithdrawalPayoutStatusEnum::CALLBACK_FAILED->value,
        ];

        if ($resultAmount !== null) {
            $updateData['result_amount'] = $resultAmount;
        }

        if ($resultCode !== null) {
            $updateData['result_code'] = $resultCode;
        }

        if ($transactionTime !== null) {
            $updateData['transaction_time'] = $transactionTime;
        }

        if ($sign !== null) {
            $updateData['sign'] = $sign;
        }

        $payoutRecord->update($updateData);
    }

    /**
     * 將三方回調狀態碼解析為三態結果。
     * 命中成功集→SUCCESS、失敗集→FAILURE、其餘（含未知/中間態）→INDETERMINATE。
     *
     * @param  array<int|string>  $successCodes
     * @param  array<int|string>  $failureCodes
     */
    protected function resolveWithdrawalOutcome(
        int|string $status,
        array $successCodes,
        array $failureCodes
    ): WithdrawalCallbackOutcome {
        $normalized = (string) $status;
        $ok = array_map('strval', $successCodes);
        $fail = array_map('strval', $failureCodes);

        if (in_array($normalized, $ok, true)) {
            return WithdrawalCallbackOutcome::SUCCESS;
        }
        if (in_array($normalized, $fail, true)) {
            return WithdrawalCallbackOutcome::FAILURE;
        }

        return WithdrawalCallbackOutcome::INDETERMINATE;
    }

    /**
     * 更新提款訂單狀態並處理交易日誌。
     *
     * - SUCCESS      → status 24（THIRD_PARTY_SUCCESS），TransactionLog status 更新為 1000
     * - FAILURE      → status 29（THIRD_PARTY_FAILED）
     * - INDETERMINATE → 不動 status（維持當前狀態：請求中 21 或在途 22），僅記錄 Log，回傳 null
     *
     * 傳入 $payoutRecord 時啟用歸屬守衛：只有訂單「最新」的 payout record
     * 可驅動訂單狀態轉換。一訂單多 record 時（後台換渠道重送），stale record
     * 的遲到回調若被放行，會把在途 record 的 22 洗成 29 解鎖重送，
     * 造成重複出款（2174206 事故，缺陷 A）。
     *
     * $paymentCost：金流商回報的實際手續費（已換算成站點幣別），成功時取代成本費率算法寫進 payment_cost；
     * 不傳則照 WithdrawalOrder::paymentCostVia() 計算。目前只有 CCPayment 傳（網路費，見其 WithdrawalCallback）。
     */
    protected function updateWithdrawalOrderStatus(
        WithdrawalOrder $order,
        WithdrawalCallbackOutcome $outcome,
        ?WithdrawalPayoutRecord $payoutRecord = null,
        ?string $paymentCost = null,
    ): ?TransactionLog {
        // INDETERMINATE：不明確 → 不動 status（維持當前狀態：請求中 21 或在途 22），僅記錄
        if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
            Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep current status', [
                'order_id' => $order->id,
                'current_status' => $order->status,
            ]);

            return null;
        }

        // 歸屬守衛：非最新 record 的回調不得改寫訂單狀態（record 層留痕不受影響）
        if ($payoutRecord !== null && $this->isStalePayoutRecord($payoutRecord)) {
            Log::warning($this->getDriverName() . ' stale payout record callback refused to drive order status', [
                'order_id' => $order->id,
                'current_status' => $order->status,
                'payout_record_id' => $payoutRecord->id,
                'transaction_no' => $payoutRecord->transaction_no,
                'outcome' => $outcome->name,
            ]);

            BotNotify::send(sprintf(
                '%s 代付回調來自非最新的請求紀錄，已拒絕改寫訂單狀態，請人工核對三方實際出款結果。訂單:%d 紀錄:%d 交易號:%s 回調結果:%s',
                $this->getDriverName(),
                $order->id,
                $payoutRecord->id,
                $payoutRecord->transaction_no,
                $outcome->name,
            ));

            return null;
        }

        $orderStatus = $outcome === WithdrawalCallbackOutcome::SUCCESS
            ? WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value
            : WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value;

        $updateData = ['status' => $orderStatus];

        // 首次轉成功才寫（沒值才寫，冪等，避免三方重送回調時覆蓋掉真正的首次成功時間）
        if ($orderStatus === WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value && $order->payment_time === null) {
            $updateData['payment_time'] = now()->toDateTimeString();
        }

        // 支付商手續費（成本）：依實際出款渠道的 cost 計算，成功才有意義 target/tickets#4361
        if ($orderStatus === WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value) {
            $updateData['payment_cost'] = $paymentCost ?? $order->paymentCostVia($payoutRecord);
        }

        $order->update($updateData);

        $transactionLog = TransactionLog::query()
            ->where('type', TransactionEnum::WITHDRAWAL->value)
            ->where('order_id', $order->id)
            ->first();

        // 如果成功，更新交易日誌
        if ($orderStatus === WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value) {
            $transactionLog?->update(['status' => 1000]);
        }

        return $transactionLog ?? null;
    }

    /**
     * 判斷 payout record 是否已非該訂單最新的一筆。
     *
     * 以 id 判序（同訂單下 id 越大越晚建立）；不依賴 is_success_payout——
     * 該欄會被回調覆寫（-2 → -3），無法區分「同步已判死」與「回調判死」。
     */
    private function isStalePayoutRecord(WithdrawalPayoutRecord $payoutRecord): bool
    {
        return WithdrawalPayoutRecord::query()
            ->where('withdrawal_order_id', $payoutRecord->withdrawal_order_id)
            ->where('id', '>', $payoutRecord->id)
            ->exists();
    }

    /**
     * 驗證回調金額是否匹配
     *
     * @param  int  $precision  精度（小數點位數）
     */
    protected function validateCallbackAmount(
        WithdrawalOrder $order,
        string|float $callbackAmount,
        WithdrawalPayoutRecord $payoutRecord,
        int $precision = 2
    ): void {
        if (Common::numCompare($order->amount, $callbackAmount, $precision) !== 0) {
            Log::warning($this->getDriverName() . ' callback amount mismatch', [
                'payoutRecordId' => $payoutRecord->id,
                'orderId' => $order->id,
                'orderAmount' => $order->amount,
                'callbackAmount' => $callbackAmount,
            ]);

            $message = sprintf(
                '%s 代付回調金額不符，回調紀錄：%s，訂單號：%s，訂單金額：%s，回調金額：%s',
                $this->getDriverName(),
                $payoutRecord->id,
                $order->id,
                $order->amount,
                $callbackAmount
            );

            BotNotify::channel('warn')->send($message);
        }
    }

    /**
     * 記錄代付回調處理日誌
     */
    protected function logCallbackProcessed(
        WithdrawalPayoutRecord $payoutRecord,
        WithdrawalOrder $order,
        bool $isSuccess
    ): void {
        if ($isSuccess) {
            Log::info($this->getDriverName() . ' callback success processed', [
                'payoutRecordId' => $payoutRecord->id,
                'orderId' => $order->id,
            ]);
        } else {
            Log::warning($this->getDriverName() . ' callback marked as failed', [
                'payoutRecordId' => $payoutRecord->id,
                'orderId' => $order->id,
            ]);
        }
    }

    /**
     * 創建代付記錄不存在的響應
     *
     * @deprecated 請改用 {@see buildWithdrawalFailResponse()}
     */
    protected function createPayoutRecordNotFoundResponse(): WithdrawalCallbackResponseData
    {
        return WithdrawalCallbackResponseData::from([
            'response' => $this->notifyFailText,
        ]);
    }

    /**
     * 創建代付記錄已處理的響應
     *
     * @deprecated 請改用 {@see buildWithdrawalSuccessResponse()}
     */
    protected function createPayoutRecordProcessedResponse(): WithdrawalCallbackResponseData
    {
        return WithdrawalCallbackResponseData::from([
            'response' => $this->notifySuccessText,
        ]);
    }

    /**
     * 創建提款訂單不存在的響應
     *
     * @deprecated 請改用 {@see buildWithdrawalFailResponse()}
     */
    protected function createWithdrawalOrderNotFoundResponse(
        WithdrawalPayoutRecord $payoutRecord,
        string $transactionNo
    ): WithdrawalCallbackResponseData {
        Log::error($this->getDriverName() . ' callback order not found', [
            'withdrawal_order_id' => $payoutRecord->withdrawal_order_id,
            'transaction_no' => $transactionNo,
        ]);

        return WithdrawalCallbackResponseData::from([
            'response' => $this->notifyFailText,
        ]);
    }

    /**
     * 創建代付回調成功響應
     *
     * @deprecated 請改用 {@see buildWithdrawalSuccessResponse()}
     */
    protected function createWithdrawalCallbackSuccessResponse(
        WithdrawalOrder $order,
        ?TransactionLog $transactionLog = null
    ): WithdrawalCallbackResponseData {
        return WithdrawalCallbackResponseData::from([
            'response' => $this->notifySuccessText,
            'order' => $order,
            'transactionLog' => $transactionLog,
        ]);
    }

    /**
     * 建立代付通用失敗響應
     *
     * 合併自以下已棄用方法：
     * - {@see createPayoutRecordNotFoundResponse()} — 代付記錄不存在
     * - {@see createWithdrawalOrderNotFoundResponse()} — 提款訂單不存在（含 Log::error）
     *
     * @param  string|null  $logMessage  若有傳入，則記錄 Log::error；為 null 時不記錄
     * @param  array<string, mixed>  $logContext  Log::error 附帶的 context
     */
    protected function buildWithdrawalFailResponse(
        ?string $logMessage = null,
        array $logContext = []
    ): WithdrawalCallbackResponseData {
        if ($logMessage !== null) {
            Log::error($this->getDriverName() . ' ' . $logMessage, $logContext);
        }

        return WithdrawalCallbackResponseData::from([
            'response' => $this->notifyFailText,
        ]);
    }

    /**
     * 建立代付通用成功響應
     *
     * 合併自以下已棄用方法：
     * - {@see createPayoutRecordProcessedResponse()} — 代付記錄已處理（冪等）
     * - {@see createWithdrawalCallbackSuccessResponse()} — 代付回調正常處理成功
     *
     * @param  WithdrawalOrder|null  $order  提款訂單（可選）
     * @param  TransactionLog|null  $transactionLog  交易日誌（可選）
     */
    protected function buildWithdrawalSuccessResponse(
        ?WithdrawalOrder $order = null,
        ?TransactionLog $transactionLog = null
    ): WithdrawalCallbackResponseData {
        return WithdrawalCallbackResponseData::from([
            'response' => $this->notifySuccessText,
            'order' => $order,
            'transactionLog' => $transactionLog,
        ]);
    }

    /**
     * 處理代付訂單狀態異常
     *
     * 統一處理：根據異常代碼返回對應響應
     * - code 200：訂單已成功處理（冪等），返回成功響應
     * - 其他：返回失敗響應並記錄警告日誌
     *
     * @param  string  $orderNo  日誌用訂單號
     */
    protected function handleWithdrawalOrderException(
        WithdrawalOrderException $exception,
        ?WithdrawalOrder $order = null,
        string $orderNo = ''
    ): WithdrawalCallbackResponseData {
        if ($exception->getCode() === 200) {
            return $this->buildWithdrawalSuccessResponse($order);
        }

        Log::warning($this->getDriverName() . ' callback rejected by order status', [
            'orderNo' => $orderNo ?: $order?->id,
            'code' => $exception->getCode(),
            'error' => $exception->getMessage(),
        ]);

        return WithdrawalCallbackResponseData::from([
            'order' => $order,
            'response' => $this->notifyFailText,
            'errorMessage' => $exception->getMessage(),
        ]);
    }
}
