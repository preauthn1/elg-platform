<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\ZPay;

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
    use ZPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理 ZPay 代付回調
     *
     * @param  Request  $request  回調請求
     * @return WithdrawalCallbackResponseData 回調響應資料
     *
     * @throws ApiException 當簽名驗證失敗時
     */
    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // ZPay 代付回調參數驗證（與代收相同結構）
        $input = $request->validate([
            'referenceId' => 'required|string',
            'itemDesc' => 'required|string',
            'status' => 'required|integer|between:1,8',
            'transactionId' => 'required|integer',
            'amount' => 'required|numeric',
            'currency' => 'required|string',
            'sourceBank' => 'nullable|string',
            'sourceAccountNo' => 'nullable|string',
            'sourceAccountName' => 'nullable|string',
            'dateTime' => 'required|string',
        ]);

        $credentials = $this->getMerchantCredentials();

        // 驗證簽名（使用回調中的 dateTime，而非系統當前時間）
        if (!$this->verifyCallbackSign(
            $input['referenceId'],
            $input['dateTime'],
            (float) $input['amount'],
            $credentials->secretKey,
            $input['itemDesc']
        )) {
            Log::error($this->getDriverName() . ' withdrawal callback signature verification failed', [
                'referenceId' => $input['referenceId'],
                'itemDesc' => $input['itemDesc'],
            ]);
            throw new ApiException(
                sprintf('%s withdrawal callback signature verification failed', $this->getDriverName())
            );
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['referenceId']);

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

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $input['referenceId']);
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                // 200 表示訂單已處理完成，回傳成功訊息給三方
                $response = ($e->getCode() === 200)
                    ? $this->successResponse()
                    : $this->failResponse();

                Log::warning($this->getDriverName() . ' withdrawal callback rejected by order status', [
                    'referenceId' => $input['referenceId'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // 三態解析：ON(1)=成功，VOIDED/FAILED/REJECTED/FORFEIT/REFUNDED(2,3,4,7,8)=失敗，PROCESSING/PENDING(5,6)=INDETERMINATE
            $outcome = $this->resolveWithdrawalOutcome(
                (string) $input['status'],
                ['1'],
                ['2', '3', '4', '7', '8']
            );

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $input['status'],
                ]);

                return $this->createWithdrawalCallbackSuccessResponse($order);
            }

            // 只有成功時才驗證金額是否匹配
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, (string) $input['amount'], $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: (string) $input['amount'],
                resultCode: (string) $input['status'],
                transactionTime: $input['dateTime'],
                sign: $input['itemDesc']
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            // 返回成功響應
            return $this->createWithdrawalCallbackSuccessResponse($order);
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
}
