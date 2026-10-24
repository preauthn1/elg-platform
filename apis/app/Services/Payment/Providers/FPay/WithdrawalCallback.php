<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FPay;

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
    use FPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 憑證未配置時 generateToken() 的 secret 段為空、token 退化為 md5(orderId) 可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        if ($this->findMissingCredentials(['APP_SECRET'], 'withdrawal callback') !== []) {
            throw new ApiException('Token verification failed');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'order_id' => 'required|string',
            'amount' => 'required|string',
            'currency' => 'required|string',
            'order_status' => 'required|string',
            'status' => 'required|boolean',
            'charge' => 'required|string',
            'token' => 'required|string',
            'name' => 'nullable|string',
            'type' => 'required|string|in:deposit,withdrawal',
        ]);

        // 驗證簽名
        if ($this->generateToken($this->info['APP_SECRET'], $input['order_id']) !== $input['token']) {
            throw new ApiException('Token verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['order_id']);

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

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $input['order_id']);
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
                    'orderNo' => $input['order_id'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            $status = $input['order_status'];
            $outcome = $this->resolveWithdrawalOutcome($status, ['completed'], ['fail', 'rejected']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id, 'status' => $status,
                ]);

                return $this->createWithdrawalCallbackSuccessResponse($order);
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
                resultCode: $input['order_status'],
                transactionTime: now()->toDateTimeString(),
                sign: $input['token']
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

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
