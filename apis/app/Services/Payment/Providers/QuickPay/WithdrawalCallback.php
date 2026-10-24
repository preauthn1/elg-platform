<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\QuickPay;

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
    use QuickPayTrait;

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
        // 憑證未配置時 generateSignature() 的 key 段為空、簽名可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤的例外相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['SECRET_KEY'], 'withdrawal callback') !== []) {
            throw new ApiException('Signature verification failed');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'status' => 'required|integer',
            'sign' => 'required|string',
            'result' => 'required|json',
        ]);

        // 驗證簽名
        if ($input['sign'] !== $this->generateSignature($input, $this->info['SECRET_KEY'])) {
            throw new ApiException('Signature verification failed');
        }

        $result = json_decode($input['result'], true);
        $status = $input['status'];
        // 純二分：10000=成功，其他=失敗，無 INDETERMINATE 路徑
        $outcome = (int) $status === 10000
            ? WithdrawalCallbackOutcome::SUCCESS
            : WithdrawalCallbackOutcome::FAILURE;
        $orderNo = $result['orderid'];
        $amount = $result['amount'];

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($orderNo);

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

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $orderNo);
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

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $amount, $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $amount,
                resultCode: $status,
                sign: $input['sign']
            );

            // 記錄更新前的訂單狀態
            Log::info($this->getDriverName() . ' before updateWithdrawalOrderStatus', [
                'orderId' => $order->id,
                'currentStatus' => $order->status,
                'isSuccessPayout' => $outcome === WithdrawalCallbackOutcome::SUCCESS,
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
