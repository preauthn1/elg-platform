<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VtPay;

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
    use VtPayTrait;

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
        // 1. 獲取加密的回調數據
        $encryptedData = $request->input('response');

        if (empty($encryptedData)) {
            Log::error($this->getDriverName() . ' withdrawal callback data is empty');
            throw new ApiException('Callback data is empty');
        }

        // 2. AES 解密回調數據
        $input = $this->decrypt($encryptedData);

        // 3. 驗證必要字段（根據文檔：sh_order_no, order_status, order_amount, paid_amount）
        $requiredFields = ['sh_order_no'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field])) {
                Log::error($this->getDriverName() . ' withdrawal callback missing required field', [
                    'field' => $field,
                    'input' => $input,
                ]);
                throw new ApiException('Missing required field: ' . $field);
            }
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['sh_order_no']);

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

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $input['sh_order_no']);
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                // 返回加密的響應
                $responseData = ['result' => 'success'];
                $encryptedResponse = $this->encryptCallbackResponse($responseData);

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'orderNo' => $input['sh_order_no'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $encryptedResponse,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // 判斷代付結果（order_status: 3=成功, 5=失敗, 其餘→INDETERMINATE）
            $status = (int) ($input['order_status'] ?? 0);
            $outcome = $this->resolveWithdrawalOutcome((string) $status, ['3'], ['5']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id, 'status' => $status,
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => [
                        'error_code' => 200,
                        'data' => [
                            'order_id' => '',
                            'sh_order_no' => $input['sh_order_no'] ?? '',
                        ],
                    ],
                ]);
            }

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS && isset($input['paid_amount'])) {
                $this->validateCallbackAmount($order, $input['paid_amount'], $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: (string) ($input['paid_amount'] ?? $input['order_amount'] ?? 0),
                resultCode: $input['order_status'] ?? 0,
                transactionTime: date('Y-m-d H:i:s'),
                sign: ''
            );

            // 記錄更新前的訂單狀態
            Log::debug($this->getDriverName() . ' before updateWithdrawalOrderStatus', [
                'orderId' => $order->id,
                'currentStatus' => $order->status,
                'outcome' => $outcome->name,
            ]);

            // 更新訂單狀態並處理交易日誌
            $transactionLog = $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            // 記錄更新後的訂單狀態
            $order->refresh();

            Log::debug($this->getDriverName() . ' after updateWithdrawalOrderStatus', [
                'orderId' => $order->id,
                'updatedStatus' => $order->status,
            ]);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return WithdrawalCallbackResponseData::from([
                'order' => $order,
                'response' => [
                    'error_code' => 200,
                    'data' => [
                        'order_id' => $transactionLog->transaction_no ?? '',
                        'sh_order_no' => $input['sh_order_no'] ?? '',
                    ],
                ],
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
