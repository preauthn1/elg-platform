<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\A3Pay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A3Pay 代付回調（api.md Payout Callback），三態。
 *
 * 信封：TransactionId／MerchantTransactionId／Data（AES）／Signature／Version；Data 解密後含 MerchantTransactionId／Status／Amount。
 * 碼集依 api.md Payout Status：4 Success、5 Fail，其餘 Processing → INDETERMINATE 維持 22。
 *
 * @knowledge rules/provider-a3pay
 */
class WithdrawalCallback extends AbstractMethod
{
    use A3PayTrait;

    /** @var list<string> api.md Payout Status：4 Success */
    private const array SUCCESS_CODES = ['4'];

    /** @var list<string> api.md Payout Status：5 Fail */
    private const array FAILURE_CODES = ['5'];

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        $requestData = $request->all();
        $data = null;

        try {
            if (!isset($requestData['Data'], $requestData['Signature'])) {
                throw new ApiException('Missing required callback parameters');
            }

            // 先解密，再對明文驗簽（api.md「Verify Signature」）
            $decryptedData = $this->aesDecrypt($requestData['Data'], $this->info[self::FIELD_AES_KEY]);
            $data = json_decode($decryptedData, true);

            if (!is_array($data)) {
                throw new ApiException('Failed to parse callback data');
            }

            Log::info($this->getDriverName() . ' withdrawal callback received', ['data' => $data]);

            if (!isset($data['MerchantTransactionId'], $data['Status'], $data['Amount'])) {
                throw new ApiException('Invalid callback data structure');
            }

            // 外部數值入境即正規化；非數值直接拒絕，不讓它流進金額比對
            if (!is_numeric($data['Amount'])) {
                throw new ApiException('Invalid callback amount');
            }

            $amount = Common::numAdd('0', (string) $data['Amount'], 4);

            if (!$this->verifyRsaSignature($decryptedData, $requestData['Signature'], $this->info[self::FIELD_RSA_PUBLIC_KEY])) {
                throw new ApiException('Invalid signature');
            }

            DB::beginTransaction();

            $payoutRecord = $this->findAndLockPayoutRecord((string) $data['MerchantTransactionId']);

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->createPayoutRecordNotFoundResponse();
            }

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->createPayoutRecordProcessedResponse();
            }

            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, (string) $data['MerchantTransactionId']);
            }

            try {
                // 放行與拒絕統一由框架決定（21／22 放行，29 拒絕並告警）
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'orderNo' => $data['MerchantTransactionId'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $e->getCode() === 200 ? $this->notifySuccessText : $this->notifyFailText,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            $status = (int) $data['Status'];
            $outcome = $this->resolveWithdrawalOutcome((string) $status, self::SUCCESS_CODES, self::FAILURE_CODES);

            // 處理中／未知碼：不寫任何 DB、維持 22，只 log ＋ ack
            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();

                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $status,
                ]);

                return $this->createWithdrawalCallbackSuccessResponse($order);
            }

            // 只有成功時驗金額
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $amount, $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $data,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $amount,
                resultCode: (string) $status,
                transactionTime: now()->toDateTimeString(),
                sign: (string) $requestData['Signature']
            );

            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->createWithdrawalCallbackSuccessResponse($order);
        } catch (Throwable $e) {
            // 接 Throwable 而非 Exception：金額比對拋的 ValueError 屬 Error，漏接會讓交易與鎖留到連線結束
            DB::rollBack();

            Log::error($this->getDriverName() . ' 代付回調處理失敗', [
                'order_no' => $data['MerchantTransactionId'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
