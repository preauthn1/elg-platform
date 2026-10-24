<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Kira;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/payout-submit-and-status
 */
class WithdrawalCallback extends AbstractMethod
{
    use KiraTrait;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 檢查配置
        $symmetricKey = $this->info['CLIENT_SYMMETRIC_KEY'] ?? null;
        if (!$symmetricKey) {
            throw new ApiException(sprintf('%s configuration missing: CLIENT_SYMMETRIC_KEY', $this->getDriverName()));
        }

        $input = $request->validate([
            'encryptedData' => 'required|string',
        ]);

        $encryptedData = $input['encryptedData'];
        if (empty($encryptedData)) {
            Log::warning($this->getDriverName() . ' Withdrawal Callback missing encryptedData', [
                'request' => $request->all(),
            ]);
            throw new ApiException('Missing encryptedData in callback');
        }

        // 解密回調數據
        try {
            $callbackData = $this->decryptCallbackData($encryptedData);
        } catch (Exception $e) {
            Log::error($this->getDriverName() . ' Withdrawal Callback decryption failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info($this->getDriverName() . ' Withdrawal Callback Received', [
            'status' => $callbackData['data']['status'] ?? null,
            'order_id' => $callbackData['data']['order']['id'] ?? null,
            'transaction_id' => $callbackData['data']['transaction']['id'] ?? null,
        ]);

        // 取得訂單資訊
        $orderData = $callbackData['data']['order'] ?? [];
        $transactionData = $callbackData['data']['transaction'] ?? [];
        $recipientData = $callbackData['data']['recipient'] ?? [];
        $status = $callbackData['data']['status'] ?? '';

        $transactionNo = $orderData['id'] ?? null;
        $thirdPartyTransactionNo = (string) ($transactionData['id'] ?? '');

        if (!$transactionNo) {
            throw new ApiException('Missing order id in callback data');
        }

        $normalizedStatus = strtoupper(trim((string) $status));

        $rejectedRecordWithSuccessCallback = null;

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄
            // 走共用方法才會套用渠道限定（target/tickets#4449 需求 4）
            $payoutRecord = $this->findAndLockPayoutRecord($transactionNo);

            if (!$payoutRecord) {
                DB::rollBack();

                Log::warning($this->getDriverName() . ' Withdrawal Callback payout record not found', [
                    'transaction_no' => $transactionNo,
                ]);

                return $this->createPayoutRecordNotFoundResponse();
            }

            // 代付請求記錄已處理
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->createPayoutRecordProcessedResponse();
            }

            // 同步回應已判明確拒絕（-2）的請求收到回調
            if ($payoutRecord->is_success_payout === WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED) {
                // 非 SUCCESS：跟同步結論不衝突、沒有出款，只記錄並回 OK（見 acknowledgeRejectedRecordCallback()）
                if ($normalizedStatus !== 'SUCCESS') {
                    $this->acknowledgeRejectedRecordCallback($payoutRecord, $normalizedStatus, $callbackData);
                    DB::commit();

                    return $this->buildWithdrawalSuccessResponse();
                }

                // SUCCESS：照常走下方的狀態檢查與定案，交易結束後再發專用告警（見 finally）
                $rejectedRecordWithSuccessCallback = $payoutRecord;
            }

            // 查詢訂單（加鎖）
            $order = WithdrawalOrder::where('id', $payoutRecord->withdrawal_order_id)->lockForUpdate()->first();

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200) ? $this->notifySuccessText : $this->notifyFailText;

                Log::warning($this->getDriverName() . ' Withdrawal Callback rejected by order status', [
                    'transaction_no' => $transactionNo,
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'response' => $response,
                ]);
            }

            // status: SUCCESS=成功, FAILED=失敗, 其餘（PENDING等）=INDETERMINATE
            $outcome = $this->resolveWithdrawalOutcome($normalizedStatus, ['SUCCESS'], ['FAILED']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' Withdrawal Callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $status,
                ]);

                return $this->buildWithdrawalSuccessResponse($order);
            }

            // 取得回調金額
            $callbackAmount = $transactionData['amountWithdraw'] ?? null;

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $callbackAmount, $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $callbackData,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $callbackAmount !== null ? (string) $callbackAmount : null,
                resultCode: $status,
                transactionTime: gmdate('Y-m-d H:i:s'),
                sign: null
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                Log::info($this->getDriverName() . ' Withdrawal Callback success processed', [
                    'payoutRecordId' => $payoutRecord->id,
                    'orderId' => $order->id,
                    'transactionNo' => $transactionNo,
                    'thirdPartyOrderNo' => $thirdPartyTransactionNo,
                ]);
            } else {
                Log::warning($this->getDriverName() . ' Withdrawal Callback marked as failed', [
                    'payoutRecordId' => $payoutRecord->id,
                    'orderId' => $order->id,
                    'status' => $status,
                ]);
            }

            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' Withdrawal Callback exception', [
                'transaction_no' => $transactionNo,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        } finally {
            // 專用告警一律等交易結束才送：BotNotify 的派發會等交易 commit，
            // 在交易內送出的告警，遇到狀態檢查拒絕的 rollBack 會跟著被丟掉
            if ($rejectedRecordWithSuccessCallback !== null) {
                $this->notifyRejectedRecordSucceeded($rejectedRecordWithSuccessCallback);
            }
        }
    }

    /**
     * 同步回應已判明確拒絕（-2）的代付紀錄收到非 SUCCESS 回調（FAILED、處理中等）：只記錄，不動訂單也不告警。
     * 呼叫時交易已開啟、紀錄已鎖定，交易由呼叫端 commit。
     *
     * 明確拒絕代表確定不會出款，KIRA 事後仍可能補送回調（業務碼 3001 實測約半數會補 FAILED），跟同步結論不衝突。
     * 訂單此時通常已是三方失敗(29)或已重新發起：交給狀態檢查會拒絕回調並回 FAIL，
     * 已重新發起的單還會觸發「回調來自非最新的請求紀錄」告警。
     * FAILED 寫入回調內容留痕；其他狀態沿用「非終態回調不寫 DB」的慣例，只記 log。
     * 紀錄維持 -2 不改 -3，同一個回調重送時仍走這條、不告警。
     * 訂單若因流程中斷仍停在代付請求中(21)，這裡不替它定案，由卡單排程告警後人工處理。
     *
     * @param  array<string, mixed>  $callbackData
     */
    private function acknowledgeRejectedRecordCallback(
        WithdrawalPayoutRecord $payoutRecord,
        string $normalizedStatus,
        array $callbackData,
    ): void {
        if ($normalizedStatus === 'FAILED') {
            $payoutRecord->update([
                'callback_request_body' => Common::safeJsonEncode($callbackData),
                'callback_time' => now()->toDateTimeString(),
                'result_code' => $normalizedStatus,
            ]);
        }

        Log::info($this->getDriverName() . ' Withdrawal Callback on synchronously rejected record acknowledged without touching order', [
            'payoutRecordId' => $payoutRecord->id,
            'orderId' => $payoutRecord->withdrawal_order_id,
            'transactionNo' => $payoutRecord->transaction_no,
            'status' => $normalizedStatus,
        ]);
    }

    /**
     * 同步回應已判明確拒絕（-2）的代付紀錄收到 SUCCESS 回調：拒絕判定可能有誤、訂單可能重複出款，
     * 不論訂單目前狀態一律發專用告警，交人工核對三方實際出款結果。
     *
     * 必須在交易結束後呼叫：BotNotify 的派發會等交易 commit（SendTelegramNotification::dispatchSafely），
     * 在交易內送出的告警，遇到狀態檢查拒絕的 rollBack 會跟著被丟掉。
     */
    private function notifyRejectedRecordSucceeded(WithdrawalPayoutRecord $payoutRecord): void
    {
        Log::warning($this->getDriverName() . ' Withdrawal Callback SUCCESS on synchronously rejected record', [
            'payoutRecordId' => $payoutRecord->id,
            'orderId' => $payoutRecord->withdrawal_order_id,
            'transactionNo' => $payoutRecord->transaction_no,
        ]);

        BotNotify::send(sprintf(
            '%s 代付同步回應已判明確拒絕（確定不會出款），卻收到成功回調：拒絕判定可能有誤，訂單可能重複出款，請立即人工核對三方實際出款結果。訂單:%d 紀錄:%d 交易號:%s',
            $this->getDriverName(),
            $payoutRecord->withdrawal_order_id,
            $payoutRecord->id,
            $payoutRecord->transaction_no,
        ));
    }
}
