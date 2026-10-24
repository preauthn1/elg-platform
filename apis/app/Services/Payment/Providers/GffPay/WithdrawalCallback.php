<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GffPay;

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
    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'Success';

    protected string|array $notifyFailText = 'Fail';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 憑證未配置時 sign() 的 secretKey 段為空、簽名可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        if ($this->findMissingCredentials(['MerchantKey'], 'withdrawal callback') !== []) {
            return WithdrawalCallbackResponseData::from([
                'response' => ['code' => 400, 'message' => 'Invalid signature'],
            ]);
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'currency_code' => 'required|string',
            'merchant_code' => 'required|string',
            'order_no' => 'required|string',
            'merchant_order_no' => 'required|string',
            'status' => 'required',
            'amount' => 'required',
            'sign' => 'required|string',
        ]);

        // 驗證簽名
        $signature = $this->sign($input, $this->info['MerchantKey']);
        if ($signature !== $input['sign']) {
            return WithdrawalCallbackResponseData::from([
                'response' => ['code' => 400, 'message' => 'Invalid signature'],
            ]);
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）- 通過三方訂單號和我方訂單號
            $payoutRecord = $this->findAndLockPayoutRecordByThirdPartyNo(
                $input['order_no'],
                $input['merchant_order_no']
            );

            if (!$payoutRecord) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => ['code' => 400, 'message' => 'Order not exist'],
                ]);
            }

            // 代付請求記錄已處理
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return WithdrawalCallbackResponseData::from([
                    'response' => ['code' => 200, 'message' => 'Order already processed'],
                ]);
            }

            // 查詢訂單（加鎖）
            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                Log::error($this->getDriverName() . ' callback order not found', [
                    'withdrawal_order_id' => $payoutRecord->withdrawal_order_id,
                    'transaction_no' => $input['merchant_order_no'],
                ]);

                return WithdrawalCallbackResponseData::from([
                    'response' => ['code' => 400, 'message' => 'Order not exist'],
                ]);
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                // GffPay 特殊響應格式
                $response = ($e->getCode() === 200)
                    ? ['code' => 200, 'message' => 'Order already processed']
                    : ['code' => 400, 'message' => $e->getMessage()];

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'orderNo' => $input['merchant_order_no'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // 判斷代付結果 (status: 1=等待付款→INDETERMINATE, 2=成功→SUCCESS, 3=失敗→FAILURE)
            $status = (string) $input['status'];
            $outcome = $this->resolveWithdrawalOutcome($status, ['2'], ['3']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id, 'status' => $status,
                ]);

                return WithdrawalCallbackResponseData::from([
                    'response' => ['code' => 200, 'message' => 'Success'],
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
                transactionTime: gmdate('Y-m-d H:i:s'),
                sign: $input['sign']
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            // GffPay 特殊響應格式
            return WithdrawalCallbackResponseData::from([
                'response' => ['code' => 200, 'message' => 'Success'],
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

    /**
     * 生成簽名
     */
    private function sign(array $input, string $secretKey): string
    {
        unset($input['sign']);
        ksort($input);
        $signString = '';
        foreach ($input as $k => $v) {
            $signString .= $k . '=' . $v . '&';
        }
        $signString = rtrim($signString, '&');
        $signString .= $secretKey;
        $sign = strtoupper(md5($signString));

        return $sign;
    }
}
