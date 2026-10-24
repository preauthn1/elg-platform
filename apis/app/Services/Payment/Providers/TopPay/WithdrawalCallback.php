<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TopPay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalCallback extends AbstractMethod
{
    use TopPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'SUCCESS';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        $input = $request->validate([
            'mchNo' => 'nullable|string',
            'platOrderNum' => 'required|string',
            'version' => 'nullable|string',
            'orderNum' => 'required|string',
            'amount' => 'required|numeric',
            'fee' => 'nullable|numeric',
            'feeType' => 'nullable|integer',
            'status' => 'required|integer',
            'statusMsg' => 'nullable|string',
            'bankCode' => 'nullable|string',
            'bankCard' => 'nullable|string',
            'accountName' => 'nullable|string',
            'description' => 'nullable|string',
            'downNotifyUrl' => 'nullable|string',
            'timestamp' => 'nullable|string',
            'sign' => 'required|string',
        ]);

        if (!$this->verifyCallbackSign($input)) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            $payoutRecord = $this->findAndLockPayoutRecord($input['orderNum']);

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse(
                    logMessage: 'payout record not found',
                    logContext: ['transaction_no' => $input['orderNum']],
                );
            }

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->buildWithdrawalSuccessResponse();
            }

            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse(
                    logMessage: 'callback order not found',
                    logContext: [
                        'withdrawal_order_id' => $payoutRecord->withdrawal_order_id,
                        'transaction_no' => $input['orderNum'],
                    ],
                );
            }

            try {
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                return $this->handleWithdrawalOrderException(
                    exception: $e,
                    order: $order,
                    orderNo: $input['orderNum'],
                );
            }

            // Pay-Out status (integer): 2=成功, 3=失敗, 5=退款已完成（終態失敗）, 0/1/4=處理中→INDETERMINATE
            $status = (string) $input['status'];
            $outcome = $this->resolveWithdrawalOutcome($status, ['2'], ['3', '5']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $status,
                ]);

                return $this->buildWithdrawalSuccessResponse($order);
            }

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $input['amount'], $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $input['amount'],
                resultCode: $status,
                transactionTime: now()->toDateTimeString(),
            );

            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' withdrawal callback exception', [
                'input' => $input,
                'error' => $e->getMessage(),
                'trace' => \App\Helpers\Common::formatTrace($e),
            ]);

            throw $e;
        }
    }
}
