<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BigPayz Payout callback: POST to CallBackUrl (form params).
 * Vendor sends PascalCase only: RefId, Signature, Status, Amount, PaymentDate, StatusMessage, InvoiceNo, etc.
 * Signature = HMAC-SHA256(InvoiceNo+Status+Currency+Amount+PaymentDate, API Key).
 * Status: 0=In Process, 1=Success, 2=Rejected, 3=Manual Approve.
 */
class WithdrawalCallback extends AbstractMethod
{
    use BigPayzTrait;

    protected string|array $notifySuccessText = 'Operation Success';

    protected string|array $notifyFailText = 'Operation Failed';

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        $input = $request->all();
        $refId = $input['RefId'] ?? null;
        $signature = $input['Signature'] ?? null;

        if (empty($refId) || empty($signature)) {
            throw new ApiException(sprintf('%s withdrawal callback missing RefId or Signature', $this->getDriverName()));
        }

        $config = $this->getConfig();
        if (!$this->verifyPayoutCallbackSignature($input, $config)) {
            Log::error(sprintf('%s 代付回調簽名驗證失敗', $this->getDriverName()), [
                'RefId' => $refId,
            ]);
            throw new ApiException(sprintf('%s withdrawal callback signature verification failed', $this->getDriverName()));
        }

        try {
            DB::beginTransaction();

            $payoutRecord = $this->findAndLockPayoutRecord((string) $refId);

            if (!$payoutRecord) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->failResponse(),
                    'order' => null,
                    'errorMessage' => 'Payout record not found: ' . $refId,
                    'httpStatusCode' => 200,
                ]);
            }

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->successResponse(),
                    'order' => null,
                    'httpStatusCode' => 200,
                ]);
            }

            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->failResponse(),
                    'order' => null,
                    'errorMessage' => 'Withdrawal order not found for payout: ' . $refId,
                    'httpStatusCode' => 200,
                ]);
            }

            try {
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();
                $response = ($e->getCode() === 200) ? $this->successResponse() : $this->failResponse();
                Log::warning(sprintf('%s 代付回調因訂單狀態遭拒絕', $this->getDriverName()), [
                    'RefId' => $refId,
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'response' => $response,
                    'order' => $order,
                    'errorMessage' => $e->getMessage(),
                    'httpStatusCode' => 200,
                ]);
            }

            if (!array_key_exists('Status', $input) || !array_key_exists('Amount', $input) || !array_key_exists('PaymentDate', $input)) {
                throw new ApiException(sprintf('%s withdrawal callback missing Status, Amount or PaymentDate', $this->getDriverName()));
            }
            $status = (string) (int) $input['Status'];
            $amount = (string) $input['Amount'];
            $paymentDate = (string) $input['PaymentDate'];

            $outcome = $this->resolveWithdrawalOutcome($status, ['1', '3'], ['2']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $status,
                ]);

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->successResponse(),
                    'order' => $order,
                    'httpStatusCode' => 200,
                ]);
            }

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $amount, $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $amount,
                resultCode: $status,
                transactionTime: $paymentDate,
                sign: $signature
            );

            $invoiceNo = (string) ($input['InvoiceNo'] ?? '');
            if ($invoiceNo !== '') {
                $payoutRecord->update(['third_party_transaction_no' => $invoiceNo]);
                $order->update(['third_party_order_no' => $invoiceNo]);
            }

            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return WithdrawalCallbackResponseData::from([
                'response' => $this->successResponse(),
                'order' => $order,
                'httpStatusCode' => 200,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error(sprintf('%s 代付回調處理異常', $this->getDriverName()), [
                'RefId' => $refId,
                'error' => $e->getMessage(),
            ]);
            BotNotify::channel('error')->send(
                sprintf('%s withdrawal callback error: %s', $this->getDriverName(), $e->getMessage())
            );
            throw $e;
        }
    }
}
