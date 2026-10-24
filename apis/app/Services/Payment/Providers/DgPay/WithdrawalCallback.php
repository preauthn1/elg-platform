<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\DgPay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalCallback extends AbstractMethod
{
    use DgPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    // DgPay 代付回調以 JSON 應答：成功 {code:0,description:Success}，失敗 {code:-100,description:Fail}
    protected string|array $notifySuccessText = ['code' => 0, 'description' => 'Success'];

    protected string|array $notifyFailText = ['code' => -100, 'description' => 'Fail'];

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * DgPay 代付回調（FORM-DATA）。
     *
     * 回調欄位：orderId（= 我方 transaction_no）、status、amount。
     * 狀態三態：20 成功、-10/-20 失敗、其餘中間態（0/1/2/5/10）維持 PAYMENT_PROCESSING(22)。
     *
     * 安全性：已依官方公式 md5(opCode+orderId+amount+status+secretKey) 驗簽，不符即拒絕。
     */
    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 憑證未配置時 verifyCallbackSignature() 的 opCode 與 secretKey 段皆為空、
        // 簽名可離線計算 ⇒ 先擋掉整個回調（代付偽造成功洗訂單成 24／偽造失敗解鎖在途重送）。
        // 雙鍵相容故傳解析後的值而非鍵名；守衛已記安全事件，logMessage 留 null 不重複落 log。
        $missingCredentials = $this->findMissingCredentialValues([
            'MERCHANT' => $this->resolveOpCode(),
            'MERCHANT_KEY' => $this->resolveSecretKey(),
        ], 'withdrawal callback');

        if ($missingCredentials !== []) {
            return $this->buildWithdrawalFailResponse();
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'orderId' => 'required|string',
            'status' => 'required|string',
            'amount' => 'required|string',
        ]);

        // 官方回調簽名驗證（硬拒）：md5(opCode + orderId + amount + status + secretKey)
        $verifyParams = $input + ['securityToken' => (string) $request->input('securityToken', '')];
        if (!$this->verifyCallbackSignature($verifyParams)) {
            return $this->buildWithdrawalFailResponse(
                logMessage: 'callback signature verification failed',
                logContext: ['orderId' => $input['orderId']],
            );
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）；IDR 回調 orderId 附 @opcode，切第一段取 transaction_no
            $transactionNo = $this->extractTransactionNo($input['orderId']);
            $payoutRecord = $this->findAndLockPayoutRecord($transactionNo);

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse(
                    logMessage: 'callback payout record not found',
                    logContext: ['orderId' => $input['orderId']],
                );
            }

            // 代付請求記錄已處理（THIRD_PARTY_SUCCESS）→ 冪等返回成功
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->buildWithdrawalSuccessResponse();
            }

            // 查詢訂單（加鎖）
            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse(
                    logMessage: 'callback order not found',
                    logContext: [
                        'withdrawal_order_id' => $payoutRecord->withdrawal_order_id,
                        'transaction_no' => $input['orderId'],
                    ],
                );
            }

            try {
                // 檢查訂單狀態（22 放行；29 補發放行；其餘拒絕）
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                return $this->handleWithdrawalOrderException(
                    exception: $e,
                    order: $order,
                    orderNo: $input['orderId'],
                );
            }

            // 三態解析：20 成功（終態）、-10/-20 失敗（終態）、其餘 → INDETERMINATE
            $outcome = $this->resolveWithdrawalOutcome($input['status'], ['20'], ['-10', '-20']);

            // INDETERMINATE：維持 PAYMENT_PROCESSING(22)，不動紀錄，回成功 ack 避免三方重送
            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();

                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $input['status'],
                ]);

                return $this->buildWithdrawalSuccessResponse($order);
            }

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $input['amount'], $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $input['amount'],
                resultCode: $input['status'],
                transactionTime: now()->toDateTimeString(),
                sign: ''
            );

            // 更新訂單狀態並處理交易日誌（成功→24，失敗→29）
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' withdrawal callback exception', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * IDR 回調 orderId 附 @opcode（{transaction_no}@{opCode}），切第一段；MYR/SGD 為原值。
     */
    private function extractTransactionNo(string $orderId): string
    {
        return str_contains($orderId, '@') ? explode('@', $orderId)[0] : $orderId;
    }
}
