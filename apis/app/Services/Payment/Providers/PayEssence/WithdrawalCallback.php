<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayEssence;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WithdrawalCallback extends AbstractMethod
{
    use PayEssenceTrait;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        $merchantCode = $this->info['MERCHANT_CODE'] ?? null;
        $secretKey = $this->info['SECRET_KEY'] ?? null;

        if (!$merchantCode || !$secretKey) {
            throw new ApiException('PayEssence configuration missing');
        }

        // 驗證請求參數格式
        $validator = Validator::make($request->all(), [
            'MerchantCode' => 'required|string',
            'TransNum' => 'required|string',
            'PayoutId' => 'required|string',
            'Amount' => 'required|string',
            'Result' => 'required|string',
            'CheckString2' => 'required|string',
            'CurrencyTypeId' => 'nullable|numeric',
            'ToBankId' => 'nullable|string',
            'ToBankAccountNum' => 'nullable|string',
            'Remarks' => 'nullable|string',
        ]);

        $input = $validator->validate();

        // 驗證商戶代碼
        if ($input['MerchantCode'] !== $merchantCode) {
            throw new ApiException('Invalid merchant code in callback');
        }

        // 驗證簽名（CheckString2）
        $checkString2 = $input['CheckString2'];
        if ($input['Result'] === '35002' && !$this->verifyWithdrawalCheckString2(
            $merchantCode,
            $secretKey,
            $input['TransNum'],
            $input['PayoutId'],
            (string) ($input['ToBankId'] ?? ''),
            (string) ($input['ToBankAccountNum'] ?? ''),
            (string) ($input['CurrencyTypeId'] ?? ''),
            (string) $input['Amount'],
            $checkString2,
        )) {
            Log::error('PayEssence Withdrawal Callback CheckString2 verification failed', [
                'transaction_num' => $input['TransNum'],
            ]);
            throw new ApiException('CheckString2 verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄
            // 走共用方法才會套用渠道限定（target/tickets#4449 需求 4）
            $payoutRecord = $this->findAndLockPayoutRecord($input['TransNum']);

            if (!$payoutRecord) {
                DB::rollBack();

                Log::warning('PayEssence Withdrawal Callback payout record not found', [
                    'transaction_num' => $input['TransNum'],
                ]);

                return $this->buildWithdrawalFailResponse();
            }

            // 代付請求記錄已處理
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->buildWithdrawalSuccessResponse();
            }

            // 查詢訂單（加鎖）
            $order = WithdrawalOrder::where('id', $payoutRecord->withdrawal_order_id)->lockForUpdate()->first();

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200) ? $this->notifySuccessText : $this->notifyFailText;

                Log::warning('PayEssence Withdrawal Callback rejected by order status', [
                    'transaction_num' => $input['TransNum'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'response' => $response,
                ]);
            }

            // 結果代碼轉換
            // 35001 = Payout is Pending  → INDETERMINATE
            // 35002 = Payout is Success  → SUCCESS
            // 35003 = Payout is Rejected → FAILURE
            // 35004 = Payout is Processing → INDETERMINATE
            $outcome = $this->resolveWithdrawalOutcome(
                $input['Result'],
                ['35002'],
                ['35003']
            );

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $input['Result'],
                ]);

                return $this->buildWithdrawalSuccessResponse($order);
            }

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $callbackAmount = $input['Amount'];
                $this->validateCallbackAmount($order, $callbackAmount, $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $input['Amount'],
                resultCode: $input['Result'],
                transactionTime: gmdate('Y-m-d H:i:s'),
                sign: $input['CheckString2']
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            if ($outcome === WithdrawalCallbackOutcome::FAILURE) {
                Log::warning('PayEssence Withdrawal Callback marked as failed', [
                    'payoutRecordId' => $payoutRecord->id,
                    'orderId' => $order->id,
                    'result_code' => $input['Result'],
                    'result_message' => $input['Remarks'] ?? '',
                ]);

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->notifyFailText,
                    'order' => $order,
                ]);
            }

            Log::info('PayEssence Withdrawal Callback success processed', [
                'payoutRecordId' => $payoutRecord->id,
                'orderId' => $order->id,
            ]);

            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('PayEssence Withdrawal Callback exception', [
                'input' => $this->maskSensitiveData($input),
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 隱遮敏感信息用於日誌
     */
    private function maskSensitiveData(array $data): array
    {
        $sensitive = ['CheckString', 'CheckString2'];
        $result = $data;

        foreach ($sensitive as $key) {
            if (isset($result[$key])) {
                $result[$key] = '******';
            }
        }

        return $result;
    }
}
