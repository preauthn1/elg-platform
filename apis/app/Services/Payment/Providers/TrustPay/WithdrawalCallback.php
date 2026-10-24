<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TrustPay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WithdrawalCallback extends AbstractMethod
{
    use TrustPayTrait;

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
        $requestAll = $request->all();
        $input = $request->validate([
            'amount' => 'required|string',
            'currency' => 'required|string',
            'hash' => 'required|string',
            'ref_no' => 'required|string',
            'status' => 'required|string',
            'transaction_reference' => 'required|string',
        ]);

        $secretKey = $this->info['SECRET_KEY'] ?? null;
        if (!$secretKey) {
            throw new ApiException('Secret key not configured');
        }

        $status = Str::lower($input['status']);
        $orderNo = $input['ref_no'];
        $amount = Common::numAdd('0', $input['amount'], 2);
        $currency = $input['currency'];

        $expectedHash = $this->generateCallbackSignature($input['transaction_reference'], $amount, $currency, $secretKey);
        if (!hash_equals($expectedHash, $input['hash'])) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            $payoutRecord = $this->findAndLockPayoutRecord($orderNo);

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

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $orderNo);
            }

            try {
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200)
                    ? $this->notifySuccessText
                    : $this->notifyFailText;

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'orderNo' => $orderNo,
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // status: completed=成功, failed=失敗, pending=INDETERMINATE
            $outcome = $this->resolveWithdrawalOutcome($status, ['completed'], ['failed']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $status,
                ]);

                return $this->buildWithdrawalSuccessResponse($order);
            }

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $amount, $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $requestAll,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $amount,
                resultCode: $input['status'],
                sign: $input['hash'],
            );

            Log::info($this->getDriverName() . ' before updateWithdrawalOrderStatus', [
                'orderId' => $order->id,
                'currentStatus' => $order->status,
                'isSuccessPayout' => $outcome === WithdrawalCallbackOutcome::SUCCESS,
            ]);

            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            $order->refresh();
            Log::info($this->getDriverName() . ' after updateWithdrawalOrderStatus', [
                'orderId' => $order->id,
                'updatedStatus' => $order->status,
            ]);

            DB::commit();

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
