<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TakaPay;

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
    use TakaPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 驗證請求參數格式（根據 API 文檔）
        $input = $request->validate([
            'status' => 'required|string', // 狀態碼
            'currencyCode' => 'required|string',
            'amount' => 'required|numeric',
            'reference' => 'required|string', // 商戶生成的參考號
            'transactionTime' => 'required|string', // yyyy-MM-dd HH:mm:ss
            'systemTransactionId' => 'required|string', // GanyoPay 生成的唯一參考碼
            'securityToken' => 'required|string', // MD5 簽名
        ]);

        // 驗證簽名
        if ($this->checkSign($input, $this->info) === false) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            $payoutRecord = $this->findAndLockPayoutRecord($input['reference']);

            if (!$payoutRecord) {
                throw new WithdrawalOrderException('Payout record not found: ' . $input['reference']);
            }

            // 已處理過的訂單回應成功（避免三方重送造成誤判）
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->successResponse(),
                    'order' => null,
                ]);
            }

            // 查詢訂單（加鎖）
            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                throw new WithdrawalOrderException('Withdrawal order not found by payoutRecord : ' . $payoutRecord->withdrawal_order_id);
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200)
                    ? $this->successResponse()
                    : $this->failResponse();

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'orderNo' => $input['reference'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // 三態解析：00000=成功，10015=失敗，10014(PENDING)→INDETERMINATE
            $outcome = $this->resolveWithdrawalOutcome(
                $input['status'],
                [TakaPayStatusCodeEnum::SUCCESS->value],
                [TakaPayStatusCodeEnum::FAILED_TRANSACTION->value]
            );

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $input['status'],
                ]);

                return WithdrawalCallbackResponseData::from([
                    'response' => $this->successResponse(),
                    'order' => $order,
                ]);
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
                transactionTime: $input['transactionTime'], // 儲存支付商的訂單時間
                sign: $input['securityToken']
            );

            if (!empty($input['systemTransactionId'])) {
                $payoutRecord->update([
                    'third_party_transaction_no' => $input['systemTransactionId'],
                ]);

                // 同時更新 withdrawal_orders.third_party_order_no
                $order->update([
                    'third_party_order_no' => $input['systemTransactionId'],
                ]);
            }

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return WithdrawalCallbackResponseData::from([
                'response' => $this->successResponse(),
                'order' => $order,
            ]);
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
