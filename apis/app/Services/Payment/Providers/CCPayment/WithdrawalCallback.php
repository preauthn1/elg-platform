<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 代付回調（api.md §5.5，Webhook type = ApiWithdrawal）。
 *
 * 三態判定的碼集逐值取自 api.md §5.5 的廠商原文列舉，禁止推測：
 *
 * | 三方 status | 廠商說明（api.md §5.5） | outcome |
 * |---|---|---|
 * | Success | The transaction has been confirmed | SUCCESS → 24 |
 * | Failed | The transaction was not completed and the funds were not received | FAILURE → 29 |
 * | Rejected | Withdrawal request has been rejected by the merchant（需啟用提現審核才會出現） | FAILURE → 29 |
 * | Processing | Blockchain is processing the transaction | INDETERMINATE |
 * | WaitingApproval | Withdrawal is waiting manual approval（需啟用提現審核才會出現） | INDETERMINATE |
 *
 * Rejected 收在 FAILURE 是因為它是「商戶主動拒絕」的明確終態；WaitingApproval 屬
 * 非終態，之後仍可能轉為 Success，誤判成失敗會導致客服改派他家重出而重複出款。
 *
 * 驗簽：api.md §5.7，三種回調共用同一套（CCPaymentTrait::assertWebhookRequest），驗簽失敗拋出、
 * 金流商會重送。驗簽之後仍強制回查，webhook 只當觸發訊號。
 *
 * 金流商手續費（withdrawal_orders.payment_cost）：成功時寫「回查的網路費 × 提款單匯率」，不走共用流程的
 * 「提款金額 × 匯率 × 成本費率」。網路費不論由會員或商戶負擔（金流商戶設定 MERCHANT_PAY_NETWORK_FEE）都照記，
 * 這是 PM 的業務決定。網路費缺漏或代幣與出款代幣不同時，告警並退回成本費率算法（networkFeeCost()）。
 *
 * @knowledge rules/payment-cost-summary
 * @knowledge rules/provider-ccpayment
 */
class WithdrawalCallback extends AbstractMethod
{
    use CCPaymentTrait;

    /** 本回調只處理商戶代付的通知；User Wallet 模式的 UserWithdrawal 不歸此處理 */
    private const EXPECTED_TYPE = 'ApiWithdrawal';

    /** @var list<string> 明確成功（api.md §5.5） */
    private const SUCCESS_CODES = ['Success'];

    /** @var list<string> 明確失敗（api.md §5.5）；非空，確保失敗路徑可達 */
    private const FAILURE_CODES = ['Failed', 'Rejected'];

    /** 廠商要求成功回應 HTTP 200 且 body 為 Success（api.md §5.2） */
    protected string|array $notifySuccessText = 'Success';

    protected string|array $notifyFailText = 'Fail';

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        $this->assertWebhookRequest($request, 'withdrawal callback');

        $input = $request->validate([
            'type' => 'required|string',
            'msg' => 'required|array',
            'msg.recordId' => 'required|string',
            'msg.orderId' => 'required|string',
            'msg.status' => 'required|string',
        ]);

        if ($input['type'] !== self::EXPECTED_TYPE) {
            throw new ApiException(sprintf(
                '%s withdrawal callback got unexpected type: %s',
                $this->getDriverName(),
                $input['type']
            ));
        }

        $msg = $input['msg'];
        $recordId = (string) $msg['recordId'];

        try {
            DB::beginTransaction();

            // orderId 是我方發起代付時送出的 transactionNo
            $payoutRecord = $this->findAndLockPayoutRecord((string) $msg['orderId']);

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->notifySuccessText,
                    'isSuccess' => true,
                ]);
            }

            $withdrawalOrder = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            // 廠商要求以 Get Withdrawal Record 確認最終狀態，webhook 只當觸發訊號
            $record = $this->queryWithdrawRecord($recordId)['data']['record'] ?? null;
            if (!is_array($record)) {
                throw new ApiException($this->getDriverName() . ' withdrawal record query returned unexpected shape');
            }

            $status = is_scalar($record['status'] ?? null) ? (string) $record['status'] : '';

            $outcome = $this->resolveWithdrawalOutcome($status, self::SUCCESS_CODES, self::FAILURE_CODES);

            // 非終態：不寫任何 DB、維持 PAYMENT_PROCESSING(22)，僅 log 並 ack 避免三方重送風暴
            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();

                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'withdrawal_order_id' => $withdrawalOrder->id,
                    'record_id' => $recordId,
                    'queried_status' => $status,
                ]);

                return $this->buildWithdrawalSuccessResponse($withdrawalOrder);
            }

            // 只有明確成功才驗金額：失敗時三方回傳的金額未必準確
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount(
                    $withdrawalOrder,
                    Common::numAdd('0', (string) ($record['amount'] ?? '0'), 4),
                    $payoutRecord
                );
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
            );

            $paymentCost = $outcome === WithdrawalCallbackOutcome::SUCCESS
                ? $this->networkFeeCost($record, $withdrawalOrder)
                : null;

            $this->updateWithdrawalOrderStatus(
                order: $withdrawalOrder,
                outcome: $outcome,
                payoutRecord: $payoutRecord,
                paymentCost: $paymentCost,
            );

            DB::commit();

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS && $paymentCost === null) {
                $this->alertAndLog('代付成功，但回查的網路費缺漏、不是數字或代幣與出款代幣不同，金流商手續費改用成本費率計算', [
                    'withdrawal_order_id' => $withdrawalOrder->id,
                    'record_id' => $recordId,
                    'coin_id' => $record['coinId'] ?? null,
                    'fee' => $record['fee'] ?? null,
                ]);
            }

            $this->logCallbackProcessed(
                $payoutRecord,
                $withdrawalOrder,
                $outcome === WithdrawalCallbackOutcome::SUCCESS
            );

            return $this->buildWithdrawalSuccessResponse($withdrawalOrder);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 網路費換算成站點幣別的金流商手續費：回查的 fee.amount × 提款單匯率。
     *
     * 金流商說明網路費與出款代幣相同（出 USDT 就收 USDT），才能直接乘提款單的匯率；
     * 回查的費用代幣不是出款代幣時無從換算，回 null 由呼叫端告警並退回成本費率算法。
     *
     * @param  array<string, mixed>  $record
     */
    private function networkFeeCost(array $record, WithdrawalOrder $order): ?string
    {
        $fee = $record['fee'] ?? null;
        if (!is_array($fee) || !is_numeric($fee['coinId'] ?? null) || !is_numeric($record['coinId'] ?? null)) {
            return null;
        }

        if ((int) $fee['coinId'] !== (int) $record['coinId']) {
            return null;
        }

        return $this->actualPaymentCost($fee['amount'] ?? null, (string) ($order->rate ?? 1));
    }
}
