<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GlobePay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Traits\WithdrawalCallbackTrait;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalCallback extends AbstractMethod
{
    use GlobePayTrait;
    use WithdrawalCallbackTrait;

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
            // 驗證必要欄位
            if (!isset($requestData['Data']) || !isset($requestData['Signature'])) {
                throw new ApiException('Missing required callback parameters');
            }

            // 解密 Data
            $decryptedData = $this->aesDecrypt($requestData['Data'], $this->info[self::FIELD_AES_KEY]);
            $data = json_decode($decryptedData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException('Failed to parse callback data');
            }

            Log::info($this->getDriverName() . ' withdrawal callback received', [
                'data' => $data,
            ]);

            // 檢查必要欄位
            if (!isset($data['MerchantTransactionId']) || !isset($data['Status']) || !isset($data['Amount'])) {
                throw new ApiException('Invalid callback data structure');
            }

            // 驗證 RSA 簽名
            $isValidSignature = $this->verifyRsaSignature(
                $decryptedData,
                $requestData['Signature'],
                $this->info[self::FIELD_RSA_PUBLIC_KEY]
            );

            if (!$isValidSignature) {
                throw new ApiException('Invalid signature');
            }

            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($data['MerchantTransactionId']);

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

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $data['MerchantTransactionId']);
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
                    'orderNo' => $data['MerchantTransactionId'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // 判斷代付結果（Status: 6=Success, 7/8=Failure, 其餘→INDETERMINATE）
            $status = (int) $data['Status'];
            $outcome = $this->resolveWithdrawalOutcome((string) $status, ['6'], ['7', '8']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id, 'status' => $status,
                ]);

                return $this->createWithdrawalCallbackSuccessResponse($order);
            }

            // 只有成功時才驗證金額是否匹配
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $data['Amount'], $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $data,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $data['Amount'],
                resultCode: $data['Status'],
                transactionTime: now()->toDateTimeString(),
                sign: $requestData['Signature']
            );

            // 更新提款訂單狀態
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->createWithdrawalCallbackSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' withdrawal callback exception', [
                'order_no' => $data['MerchantTransactionId'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
