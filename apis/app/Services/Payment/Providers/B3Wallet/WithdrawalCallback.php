<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\B3Wallet;

use App\Enums\General\HttpCode;
use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 代付回調。
 *
 * 硬規則：
 * - 🔴 **查單一律在交易外**：先不鎖讀代付請求與提款單、打查單，拿到結果才開短交易鎖定寫入。
 * - 🔴 **查單用建單時存下的 PayoutId，不用回調帶的**：回調無簽名，回調的 PayoutId 換成同商戶另一筆
 *   已付款的代付，就能把一筆沒出款的提款單標成三方成功。兩者不一致直接拒絕。
 *   建單響應遺失（沒存到 PayoutId、停在代付提交中 21）時才用回調的值，且該 PayoutId 不可已屬於別筆代付請求
 *   （本家查代付的響應不帶 Param，無法用它驗證歸屬）。
 *
 * @knowledge rules/provider-b3wallet
 */
class WithdrawalCallback extends AbstractMethod
{
    use B3WalletTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 進來時的交易層數：測試環境由 DatabaseTransactions 先開了一層，收尾只能收掉我們自己多開的
        $baseline = DB::transactionLevel();
        $response = null;

        try {
            return $response = $this->process($request);
        } finally {
            $this->closeDanglingTransaction($baseline);

            // 🔴 交易外記錄（理由見 RecordsThirdPartyExchangeTrait）；用 $request->all() 留原文
            $this->logExchange(
                action: OrderRequestActionEnum::WITHDRAWAL_CALLBACK,
                url: $request->fullUrl(),
                request: $request->all(),
                response: $response?->response,
                result: $this->result,
                requestHeaders: $request->headers->all(),
                order: $this->loggedOrder,
                orderNo: (string) $request->input('Param'),
                thirdPartyOrderNo: (string) $request->input('PayoutId'),
                sourceIp: Common::getIp(),
                httpStatus: $response?->httpStatusCode,
            );
        }
    }

    private function process(Request $request): WithdrawalCallbackResponseData
    {
        // B3 Wallet 代付回調無簽名欄位，鑑權僅靠來源 IP 白名單（external 路由層把關）
        $input = $request->validate([
            'PayoutId' => 'required|string',
            'Amount' => 'required|numeric',
            'Paid' => 'required|boolean',
            'PayoutMethod' => 'required|string|in:BANK_TRANSFER,PAYNOW,CRYPTO',
            'PayNowProxyType' => 'nullable|string',
            'RecipientAccount' => 'required|string',
            'RecipientBank' => 'required|string',
            'Param' => 'nullable|string',
            'Error' => 'nullable|string',
            // 三方帶的手續費欄位收進來備查，但不據以計算成本：支付商成本一律依
            // agent_cash_flows.cost 計算（與代收同一套口徑），避免同一家金流商兩邊算法不一致。
            'FeeAmount' => 'nullable|numeric',
            'FeeRate' => 'nullable|numeric',
            'TotalDeductAmount' => 'nullable|numeric',
        ]);

        // Param 於建付款時已寫入我方 transactionNo，用於對應代付請求記錄。
        // 不鎖：只為擋掉不需查單的情況，真正的讀改寫在下方交易內重取並鎖定
        $payoutRecord = $this->payoutRecordInCallbackChannel(
            WithdrawalPayoutRecord::where('transaction_no', (string) $input['Param'])->first()
        );
        $this->loggedOrder = $payoutRecord;

        if (!$payoutRecord) {
            $this->result = OrderRequestResultEnum::ORDER_NOT_FOUND;

            return $this->buildWithdrawalFailResponse(
                logMessage: 'payout record not found',
                logContext: ['payout_id' => $input['PayoutId'], 'param' => $input['Param']]
            );
        }

        if ($this->isPayoutRecordProcessed($payoutRecord)) {
            $this->result = OrderRequestResultEnum::ALREADY_PROCESSED;

            return $this->buildWithdrawalSuccessResponse();
        }

        $order = WithdrawalOrder::find($payoutRecord->withdrawal_order_id);
        $rejected = $this->rejectByOrderStatus($order, $payoutRecord, $input['PayoutId']);
        if ($rejected !== null) {
            return $rejected;
        }

        $payoutId = $this->resolvePayoutId($payoutRecord, $input['PayoutId']);
        if ($payoutId === null) {
            $this->result = OrderRequestResultEnum::QUERY_ANOTHER_ORDER;

            return $this->rejectAnotherPayout($payoutRecord, $input['PayoutId']);
        }

        // 無簽名機制，不可只信回調 Paid/Error，須以查詢接口二次確認（api.md「關鍵規則」第 3 點）
        try {
            $queried = $this->queryPayout($payoutId);
        } catch (Exception $e) {
            $this->result = OrderRequestResultEnum::QUERY_FAILED;

            return $this->retryLater('withdrawal callback query failed: ' . $e->getMessage(), $order, $payoutId);
        }

        // 查回來的必須正是送去查的那筆（PayoutId 直接接進 URL 路徑，防 `../` 之類把查詢導到別支 API）
        if (($queried['PayoutId'] ?? null) !== $payoutId) {
            $this->result = OrderRequestResultEnum::QUERY_ANOTHER_ORDER;

            return $this->rejectAnotherPayout($payoutRecord, $payoutId);
        }

        $outcome = $this->resolveWithdrawalOutcome(
            (string) ($queried['Status'] ?? ''),
            ['PAID'],
            ['FAILED', 'CANCELLED'],
        );

        if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
            $this->result = OrderRequestResultEnum::NOT_FINALIZED;

            Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                'order_id' => $order->id,
                'payout_id' => $payoutId,
                'queried_status' => $queried['Status'] ?? null,
            ]);

            return $this->buildWithdrawalSuccessResponse($order);
        }

        $isSuccess = $outcome === WithdrawalCallbackOutcome::SUCCESS;

        if ($isSuccess && !isset($queried['Amount'])) {
            $this->result = OrderRequestResultEnum::NO_AMOUNT;

            return $this->retryLater('withdrawal query returned PAID without amount', $order, $payoutId);
        }

        $resultAmount = isset($queried['Amount'])
            ? Common::numAdd('0', (string) $queried['Amount'], 4)
            : $input['Amount'];

        try {
            DB::beginTransaction();

            // 查單期間可能已被另一筆回調定案，鎖到之後再檢查一次
            $payoutRecord = $this->findAndLockPayoutRecord($payoutRecord->transaction_no);

            if (!$payoutRecord) {
                DB::rollBack();

                $this->result = OrderRequestResultEnum::ORDER_NOT_FOUND;

                return $this->buildWithdrawalFailResponse(logMessage: 'payout record not found', logContext: ['payout_id' => $payoutId]);
            }

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                $this->result = OrderRequestResultEnum::ALREADY_PROCESSED;

                return $this->buildWithdrawalSuccessResponse();
            }

            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            try {
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $this->result = $e->getCode() === 200
                    ? OrderRequestResultEnum::ALREADY_PROCESSED
                    // 狀態不收回調（21/22 以外），沒有對應的 enum，照 providerFailure 格式記明原因
                    : OrderRequestResultEnum::providerFailure('order_status_not_allowed');

                return $this->handleWithdrawalOrderException(exception: $e, order: $order, orderNo: $payoutId);
            }

            if ($isSuccess) {
                $this->validateCallbackAmount($order, $resultAmount, $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $isSuccess,
                resultAmount: $resultAmount,
                resultCode: $queried['Status'] ?? null,
            );

            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->result = OrderRequestResultEnum::OK;

            $this->logCallbackProcessed($payoutRecord, $order, $isSuccess);

            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' callback exception', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 這次定不了案、要三方晚點重送：回 HTTP 500 並發告警。
     *
     * 🔴 本家重送只看 HTTP 狀態（api.md「回調通知」：商戶只需回 2xx），回 2xx 的 fail 文本它就不再送了，
     * 提款單會停在 22 沒人知道（本家沒有代付主動查證）。確定是偽造或錯配的則回 2xx，不要它重送。
     */
    private function retryLater(string $reason, WithdrawalOrder $order, string $payoutId): WithdrawalCallbackResponseData
    {
        Log::error($this->getDriverName() . ' ' . $reason, ['order_id' => $order->id, 'payout_id' => $payoutId]);

        BotNotify::channel('error')->send(sprintf('%s %s，提款單:%s 代付單:%s', $this->getDriverName(), $reason, $order->id, $payoutId));

        return WithdrawalCallbackResponseData::from([
            'response' => $this->notifyFailText,
            'order' => $order,
            'errorMessage' => $reason,
            'httpStatusCode' => HttpCode::HTTP_INTERNAL_SERVER_ERROR->value,
        ]);
    }

    /**
     * 查單前先擋掉訂單狀態不收回調的情況（不鎖，交易內會再檢查一次），省掉一次查單。
     */
    private function rejectByOrderStatus(
        ?WithdrawalOrder $order,
        WithdrawalPayoutRecord $payoutRecord,
        string $payoutId,
    ): ?WithdrawalCallbackResponseData {
        if (!$order) {
            $this->result = OrderRequestResultEnum::ORDER_NOT_FOUND;

            return $this->buildWithdrawalFailResponse(
                logMessage: 'callback order not found',
                logContext: ['withdrawal_order_id' => $payoutRecord->withdrawal_order_id, 'payout_id' => $payoutId]
            );
        }

        try {
            $this->checkWithdrawalOrderStatus($order);
        } catch (WithdrawalOrderException $e) {
            $this->result = $e->getCode() === 200
                ? OrderRequestResultEnum::ALREADY_PROCESSED
                // 狀態不收回調（21/22 以外），沒有對應的 enum，照 providerFailure 格式記明原因
                : OrderRequestResultEnum::providerFailure('order_status_not_allowed');

            return $this->handleWithdrawalOrderException(exception: $e, order: $order, orderNo: $payoutId);
        }

        return null;
    }

    /**
     * 決定拿哪個 PayoutId 去查單；回 null 代表回調的 PayoutId 不屬於這筆代付請求。
     */
    private function resolvePayoutId(WithdrawalPayoutRecord $payoutRecord, string $callbackPayoutId): ?string
    {
        $stored = (string) $payoutRecord->third_party_transaction_no;

        if ($stored !== '') {
            return $stored === $callbackPayoutId ? $stored : null;
        }

        $claimedByOther = WithdrawalPayoutRecord::where('third_party_transaction_no', $callbackPayoutId)
            ->where('id', '!=', $payoutRecord->id)
            ->exists();

        return $claimedByOther ? null : $callbackPayoutId;
    }

    /**
     * 回調的代付不是這筆代付請求的：正常流程不會發生，是偽造或錯配的訊號，發告警並回 FAIL。
     */
    private function rejectAnotherPayout(
        WithdrawalPayoutRecord $payoutRecord,
        string $callbackPayoutId,
    ): WithdrawalCallbackResponseData {
        $context = [
            'payout_record_id' => $payoutRecord->id,
            'transaction_no' => $payoutRecord->transaction_no,
            'stored_payout_id' => $payoutRecord->third_party_transaction_no,
            'callback_payout_id' => $callbackPayoutId,
        ];

        BotNotify::channel('warn')->send(sprintf(
            '[代付回調遭拒：代付單不屬於此代付請求]%s%s%s%s',
            PHP_EOL,
            $this->getDriverName(),
            PHP_EOL,
            Common::safeJsonEncode($context)
        ));

        return $this->buildWithdrawalFailResponse(
            logMessage: 'withdrawal callback payout id does not belong to record',
            logContext: $context,
        );
    }
}
