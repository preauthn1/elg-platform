<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MaxPay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/payment-callback-common-checks
 */
class WithdrawalCallback extends AbstractMethod
{
    use MaxPayTrait;

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
        // 進來時的交易層數：測試環境由 RefreshDatabase 先開了一層，收尾只能收掉我們自己多開的
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
                orderNo: (string) $request->input('ref_no'),
                sourceIp: Common::getIp(),
                httpStatus: $response?->httpStatusCode,
            );
        }
    }

    private function process(Request $request): WithdrawalCallbackResponseData
    {
        // 防線 1：來源 IP（fail-closed，規則見 CallbackSourceGuardTrait）。
        //
        // 代付回調同樣沒有驗簽。風險比代收更高：還在「代付請求中」「代付處理中」的提款單
        // 收到偽造的失敗回調會先退點給會員，之後三方若真的出款就等於付了兩次。
        // 擋在 validate 之前：連欄位格式都還沒看，就不該讓不明來源的輸入影響任何狀態。
        $ip = Common::getIp();
        if (!$this->isCallbackSourceAllowed($ip, 'withdrawal callback')) {
            $this->result = OrderRequestResultEnum::IP_NOT_WHITELISTED;

            return $this->buildWithdrawalFailResponse(
                logMessage: 'withdrawal callback rejected: source IP not in whitelist',
                logContext: ['ip' => $ip],
            );
        }

        $callbackData = $request->validate([
            'status_id' => 'required|integer',
            'amount' => 'required|string',
            'ref_no' => 'required|string',
        ]);

        Log::info($this->getDriverName() . ' Withdrawal Callback Received', $callbackData);

        $status = $callbackData['status_id'];
        $transactionNo = $callbackData['ref_no'] ?? null;
        $callbackAmount = $callbackData['amount'] ?? null;

        if (!$transactionNo) {
            throw new ApiException('Missing order id in callback data');
        }

        try {
            DB::beginTransaction();

            // 走共用方法才會套用渠道限定（target/tickets#4449 需求 4）
            $payoutRecord = $this->findAndLockPayoutRecord($transactionNo);
            $this->loggedOrder = $payoutRecord;

            if (!$payoutRecord) {
                DB::rollBack();

                // 查無代付請求，含渠道不符（見 WithdrawalCallbackTrait::payoutRecordInCallbackChannel()）
                $this->result = OrderRequestResultEnum::ORDER_NOT_FOUND;

                Log::warning($this->getDriverName() . ' Withdrawal Callback payout record not found', [
                    'transaction_no' => $transactionNo,
                ]);

                return $this->buildWithdrawalFailResponse();
            }

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                $this->result = OrderRequestResultEnum::ALREADY_PROCESSED;

                return $this->buildWithdrawalSuccessResponse();
            }

            $order = WithdrawalOrder::where('id', $payoutRecord->withdrawal_order_id)->lockForUpdate()->first();

            try {
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200) ? $this->notifySuccessText : $this->notifyFailText;

                Log::warning($this->getDriverName() . ' Withdrawal Callback rejected by order status', [
                    'transaction_no' => $transactionNo,
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                // code 200 是「已處理過」的冪等回應，其餘才是狀態不允許
                $this->result = $e->getCode() === 200
                    ? OrderRequestResultEnum::ALREADY_PROCESSED
                    : OrderRequestResultEnum::NOT_FINALIZED;

                return WithdrawalCallbackResponseData::from([
                    'response' => $response,
                ]);
            }

            // status_id: 0=Pending（非終態）、1=Completed（成功終態）、2=Failed（失敗終態）
            // 依據：MaxPay README §6.2 代付回調狀態碼（工單 #3739）
            $outcome = $this->resolveWithdrawalOutcome($status, ['1'], ['2']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $status,
                ]);

                // Pending 是正常的中間態，記成 exception 會讓整張表看起來都在炸
                $this->result = OrderRequestResultEnum::NOT_FINALIZED;

                return $this->buildWithdrawalSuccessResponse($order);
            }

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $callbackAmount, $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $callbackData,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $callbackAmount !== null ? (string) $callbackAmount : null,
                resultCode: $status,
                transactionTime: gmdate('Y-m-d H:i:s'),
                sign: null
            );

            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->result = OrderRequestResultEnum::OK;

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                Log::info($this->getDriverName() . ' Withdrawal Callback success processed', [
                    'payoutRecordId' => $payoutRecord->id,
                    'orderId' => $order->id,
                    'transactionNo' => $transactionNo,
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
        }
    }
}
