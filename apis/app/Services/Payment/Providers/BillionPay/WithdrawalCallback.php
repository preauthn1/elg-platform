<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BillionPay;

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
    use BillionPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'true';

    protected string|array $notifyFailText = 'fail';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 驗證請求參數格式
        $input = $request->validate([
            'transaction_reference_id' => 'required|string',
            'bank_reference_id' => 'nullable',
            'customer_id' => 'required|string',
            'currency' => 'required|string',
            'amount' => 'required|string', // Deposit Amount
            'fee' => 'required|string', // Fee
            'status' => 'required|string',
            'bank' => 'required|string',
            'bank_account_name' => 'required|string',
            'bank_account_number' => 'required|string',
            'receipt_image' => 'nullable',
            'receipt_mime_type' => 'nullable',
            'receipt_url' => 'nullable',
            'signature' => 'required|string',
        ]);

        // 驗證簽名
        if (!$this->checkSign($input, $this->info['SECRET_KEY'])) {
            Log::error($this->getDriverName() . ' callback signature verification failed', [
                'order' => $input['transaction_reference_id'],
                'sign' => $input[$this->signField],
            ]);
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['transaction_reference_id']);

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

                return $this->createWithdrawalOrderNotFoundResponse($payoutRecord, $input['transaction_reference_id']);
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
                    'orderNo' => $input['transaction_reference_id'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // doc-literal 三態解析（依 BillionPay 文檔終態定義）：
            // completed=代付成功（終態）→ SUCCESS
            // pending=處理中（非終態）→ INDETERMINATE，保持 22，等下次推送
            // 其他（含 rejected）=代付失敗（終態）→ FAILURE，標 29
            $outcome = match (true) {
                $input['status'] === 'completed' => WithdrawalCallbackOutcome::SUCCESS,        // 終態成功
                $input['status'] === 'pending' => WithdrawalCallbackOutcome::INDETERMINATE,  // 處理中（保留，等下次推送）→ 保持 22
                default => WithdrawalCallbackOutcome::FAILURE,         // 其他（含 rejected）→ 失敗
            };

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $input['status'],
                ]);

                return $this->buildWithdrawalSuccessResponse($order);
            }

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $input['amount'], $payoutRecord);
            }

            // 金流商推送了圖片二維碼，刪掉不要紀錄
            unset($input['receipt_image']);

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $input['amount'],
                resultCode: $input['status'],
                transactionTime: now()->toDateTimeString(),
                sign: $input[$this->signField]
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

            return $this->buildWithdrawalSuccessResponse($order);
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
