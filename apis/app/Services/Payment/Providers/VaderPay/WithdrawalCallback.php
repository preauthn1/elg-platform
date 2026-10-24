<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VaderPay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
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
    use VaderPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'Operation Success';

    protected string|array $notifyFailText = 'Operation Failed';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理 VaderPay 代付回調
     *
     * @param  Request  $request  回調請求
     * @return WithdrawalCallbackResponseData 回調響應資料
     *
     * @throws ApiException 當簽名驗證失敗時
     */
    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        $rawInput = $this->parseRawInput($request);

        $input = $request->validate([
            'InvoiceNo' => 'required|string',
            'BeneficiaryAccount' => 'required|string',
            'BeneficiaryBankCode' => 'required|string',
            'BeneficiaryName' => 'required|string',
            'SubmissionDate' => 'required|string',
            'Status' => 'required|integer',
            'StatusMessage' => 'required|string',
            'MerchantCode' => 'required|string',
            'RefId' => 'required|string',
            'Currency' => 'required|string',
            'Signature' => 'required|string',
            'Amount' => 'required|numeric',
            'PaymentDate' => 'required|string',
        ]);

        $credentials = $this->getMerchantCredentials();
        if ($input['MerchantCode'] !== $credentials->merchantCode) {
            throw new ApiException(sprintf('%s withdrawal callback merchant code mismatch', $this->getDriverName()));
        }

        $signatureInput = $this->mergeSignatureInput($input, $rawInput);
        if (!$this->verifyPayoutCallbackSignature($signatureInput, $credentials)) {
            Log::error($this->getDriverName() . ' withdrawal callback signature verification failed', [
                'referenceId' => $input['RefId'],
                'invoiceNo' => $input['InvoiceNo'],
            ]);

            throw new ApiException(
                sprintf('%s withdrawal callback signature verification failed', $this->getDriverName())
            );
        }

        try {
            DB::beginTransaction();

            $payoutRecord = $this->findAndLockPayoutRecord($input['RefId']);

            if (!$payoutRecord) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->failResponse(),
                    'order' => null,
                    'errorMessage' => 'Payout record not found',
                ]);
            }

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->successResponse(),
                    'order' => null,
                ]);
            }

            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->failResponse(),
                    'order' => null,
                    'errorMessage' => 'Withdrawal order not found',
                ]);
            }

            try {
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200)
                    ? $this->successResponse()
                    : $this->failResponse();

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'referenceId' => $input['RefId'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            $status = (int) $input['Status'];

            // 以 VaderPayStatusCodeEnum 驗證狀態碼合法性（未知碼會拋 ApiException）
            VaderPayStatusCodeEnum::fromValue($status);

            // 成功碼：1(SUCCESS)、3(APPROVED）；失敗碼：2(REJECTED)；0(PENDING)→ INDETERMINATE
            // 依據：VaderPay README §7 狀態碼表、§5.2 代付各狀態處理（工單 #3449）
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
                ]);
            }

            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, (string) $input['Amount'], $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: (string) $input['Amount'],
                resultCode: (string) $status,
                transactionTime: (string) $input['SubmissionDate'],
                sign: (string) $input['Signature']
            );

            if (!empty($input['PaymentDate'])) {
                $payoutRecord->update([
                    'info_2' => (string) $input['PaymentDate'],
                ]);
            }

            if (!empty($input['InvoiceNo'])) {
                $payoutRecord->update([
                    'third_party_transaction_no' => (string) $input['InvoiceNo'],
                ]);

                $order->update([
                    'third_party_order_no' => (string) $input['InvoiceNo'],
                ]);
            }

            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return WithdrawalCallbackResponseData::from([
                'response' => $this->successResponse(),
                'order' => $order,
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' withdrawal callback exception', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send(
                sprintf('%s withdrawal callback error: %s', $this->getDriverName(), $e->getMessage())
            );

            throw $e;
        }
    }

    private function parseRawInput(Request $request): array
    {
        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return $request->all();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : $request->all();
        } catch (\JsonException) {
            return $request->all();
        }
    }

    private function mergeSignatureInput(array $validated, array $rawInput): array
    {
        $merged = $validated;
        foreach (['InvoiceNo', 'Status', 'Currency', 'Amount', 'PaymentDate', 'SubmissionDate'] as $key) {
            if (array_key_exists($key, $rawInput)) {
                $merged[$key] = (string) $rawInput[$key];
            }
        }

        return $merged;
    }
}
