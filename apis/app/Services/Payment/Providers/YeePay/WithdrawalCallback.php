<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\YeePay;

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
    use YeePayTrait;

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
        // 驗證請求參數格式
        $input = $request->validate([
            'code' => 'required|integer|in:0,1',
            'merchant_num' => 'required|string',
            'coin' => 'required|string', // 代付金額（字串格式）
            'merchant_order' => 'required|string', // 我方訂單號
            'serial_number' => 'required|string', // 平台交易號
            'order_time' => 'required|string',
            'success_time' => 'required|string',
            'message' => 'required|string',
            'sign' => 'required|string',
        ]);

        // 驗證簽名
        if (!$this->checkSign($input, $this->info['API_SECRET'])) {
            Log::error($this->getDriverName() . ' callback signature verification failed', [
                'merchant_order' => $input['merchant_order'],
                'merchant_num' => $input['merchant_num'],
                'sign' => $input['sign'],
            ]);
            throw new ApiException('Token verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['merchant_order']);

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->createPayoutRecordNotFoundResponse();
            }

            // 代付請求記錄已處理
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->createPayoutRecordProcessedResponse();
            }

            // 查詢訂單（加鎖）
            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $input['merchant_order']);
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
                    'orderNo' => $input['merchant_order'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // doc-literal 二分解析（依 YeePay 文檔終態定義）：
            // code=1=代付成功（終態）→ SUCCESS
            // 其他（code=0 或任何非 1）=代付失敗（終態）→ FAILURE，標 29
            // 注意：YeePay 無任何非終態碼，無 INDETERMINATE 路徑
            $outcome = (int) $input['code'] === 1
                ? WithdrawalCallbackOutcome::SUCCESS
                : WithdrawalCallbackOutcome::FAILURE;

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $input['coin'], $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $input['coin'],
                resultCode: $input['code'],
                transactionTime: $input['success_time'],
                sign: $input['sign']
            );

            // 記錄更新前的訂單狀態
            Log::info($this->getDriverName() . ' before updateWithdrawalOrderStatus', [
                'orderId' => $order->id,
                'currentStatus' => $order->status,
                'outcome' => $outcome->name,
                'willUpdateTo' => $outcome === WithdrawalCallbackOutcome::SUCCESS ? 24 : 29,
            ]);

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            // 記錄更新後的訂單狀態
            $order->refresh();
            Log::info($this->getDriverName() . ' after updateWithdrawalOrderStatus', [
                'orderId' => $order->id,
                'updatedStatus' => $order->status,
            ]);

            DB::commit();

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
