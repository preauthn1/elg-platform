<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EasyPay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
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
    use EasyPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

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
                orderNo: (string) $request->input('tx_id'),
                thirdPartyOrderNo: (string) $request->input('order_id'),
                sourceIp: Common::getIp(),
                httpStatus: $response?->httpStatusCode,
            );
        }
    }

    private function process(Request $request): WithdrawalCallbackResponseData
    {
        // 防線 1：來源 IP（fail-closed，規則見 CallbackSourceGuardTrait）。
        //
        // 驗簽是防線 2，但它按商戶開關、預設關閉，沒開的商戶回調不帶簽名 ⇒ 來源 IP 仍是唯一防線。
        // 代付的風險比代收更高：還在「代付請求中」「代付處理中」的提款單收到偽造的失敗回調會先
        // 退點給會員，之後三方若真的出款就等於付了兩次。
        // 擋在 validate 之前：連欄位格式都還沒看，就不該讓不明來源的輸入影響任何狀態。
        $ip = Common::getIp();
        if (!$this->isCallbackSourceAllowed($ip, 'withdrawal callback')) {
            $this->result = OrderRequestResultEnum::IP_NOT_WHITELISTED;

            return $this->buildWithdrawalFailResponse(
                logMessage: 'withdrawal callback rejected: source IP not in whitelist',
                logContext: ['ip' => $ip],
            );
        }

        // 防線 2：回調簽名（只在三方真的帶了 mdfive_val 時才驗，見 EasyPayTrait::isCallbackSignatureValid）
        if (!$this->isCallbackSignatureValid($request->all())) {
            $this->result = OrderRequestResultEnum::INVALID_SIGNATURE;

            return $this->buildWithdrawalFailResponse(
                logMessage: 'withdrawal callback rejected: invalid signature',
                logContext: ['ip' => $ip],
            );
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'tx_id' => 'required|string',
            'status' => 'required|string',
            'order_id' => 'required|string',
            'final_amount' => 'required|string',
            'order_amount' => 'required|string',
            'service_change' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['tx_id']);
            $this->loggedOrder = $payoutRecord;

            if (!$payoutRecord) {
                DB::rollBack();

                // 查無代付請求，含渠道不符（見 WithdrawalCallbackTrait::payoutRecordInCallbackChannel()）
                $this->result = OrderRequestResultEnum::ORDER_NOT_FOUND;

                return $this->createPayoutRecordNotFoundResponse();
            }

            // 代付請求記錄已處理
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                $this->result = OrderRequestResultEnum::ALREADY_PROCESSED;

                return $this->createPayoutRecordProcessedResponse();
            }

            // 查詢訂單（加鎖）
            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                $this->result = OrderRequestResultEnum::ORDER_NOT_FOUND;

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $input['tx_id']);
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200)
                    ? $this->notifySuccessText
                    : $this->notifyFailText;

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'orderNo' => $input['tx_id'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                // code 200 是「已處理過」的冪等回應，其餘才是狀態不允許
                $this->result = $e->getCode() === 200
                    ? OrderRequestResultEnum::ALREADY_PROCESSED
                    : OrderRequestResultEnum::NOT_FINALIZED;

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // 依官方文檔 status enum 三態解析（代付 callback：COMPLETE / REJECT / PENDING）：
            // COMPLETE=代付成功（終態）→ SUCCESS
            // REJECT=代付失敗（終態）→ FAILURE，標 29
            // PENDING 及其他未知=非終態 → INDETERMINATE，維持 PAYMENT_PROCESSING(22) 等待後續終態回調，
            // 避免把「處理中」誤判為失敗而退款、與三方事後成功打款造成雙重出款資損
            $outcome = $this->resolveWithdrawalOutcome($input['status'], ['COMPLETE'], ['REJECT']);

            // INDETERMINATE：不動訂單狀態（維持 22）、不寫入 payoutRecord，回 success ack 等待後續終態回調
            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();

                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $input['status'],
                ]);

                // PENDING 是正常的中間態、量不小，記成 exception 會讓整張表看起來都在炸
                $this->result = OrderRequestResultEnum::NOT_FINALIZED;

                return $this->createWithdrawalCallbackSuccessResponse($order);
            }

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $input['order_amount'], $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $input['order_amount'],
                resultCode: $input['status'],
                transactionTime: now()->toDateTimeString(),
                sign: null
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->result = OrderRequestResultEnum::OK;

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->createWithdrawalCallbackSuccessResponse($order);
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
}
