<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayPro;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalCallback extends AbstractMethod
{
    use PayProTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 憑證未配置時簽名的 secret／key 段為空、簽名可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials([$this->merchantKeyField], 'withdrawal callback') !== []) {
            throw new Exception('Invalid signature');
        }

        // {
        //     "sign": "4b51dc9b09d17c8f23244f95b50c5e1f",
        //     "timestamp": "1770108032294",
        //     "status": "0000",
        //     "tradeNo": "PayPro2026020308342212734",
        //     "message": "Mock success status",
        //     "platFormTradeNo": "Y000ewzvrwsyivl7rgjx_da",
        //     "amount": 10000,
        //     "successTime": "2026-02-03 16:40:32"
        //   }

        // 驗證請求參數格式
        $request->validate([
            'platFormTradeNo' => 'required|string',
            'tradeNo' => 'required|string',
            'amount' => 'required|numeric',
            'status' => 'required|string',
            'message' => 'nullable|string',
            'timestamp' => 'nullable|string',
            'sign' => 'required|string',
        ]);

        // 有一些不用驗證的參數，直接從 request 中取得
        $input = $request->all();

        // 驗證簽名
        if (!$this->verifySign($input)) {
            Log::error($this->getDriverName() . ' 代付回調簽名驗證失敗', [
                'input' => $this->maskSensitiveParams($input),
            ]);
            throw new Exception('Invalid signature');
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['tradeNo']);

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse();
            }

            // 代付請求記錄已處理
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->buildWithdrawalSuccessResponse();
            }

            // 查詢訂單（加鎖）
            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse('代付訂單不存在', ['tradeNo' => $input['tradeNo']]);
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                $response = ($e->getCode() === 200)
                    ? $this->notifySuccessText
                    : $this->notifyFailText;

                Log::warning($this->getDriverName() . ' 代付回調因訂單狀態被拒', [
                    'orderNo' => $input['tradeNo'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                return WithdrawalCallbackResponseData::from([
                    'order' => $order,
                    'response' => $response,
                    'errorMessage' => $e->getMessage(),
                ]);
            }

            // doc-literal 三態解析（依 PayPro 文檔終態定義）：
            // 0000=代付成功（終態）→ SUCCESS
            // 0015=處理中（非終態）→ INDETERMINATE，保持 22，等下次推送
            // 其他=代付失敗（終態）→ FAILURE，標 29
            $status = $input['status'] ?? '';

            $outcome = match (true) {
                $status === '0000' => WithdrawalCallbackOutcome::SUCCESS,        // 代付成功（終態）
                $status === '0015' => WithdrawalCallbackOutcome::INDETERMINATE,  // 處理中（非終態）→ 保持 22
                default => WithdrawalCallbackOutcome::FAILURE,         // 其他 → 代付失敗（終態）
            };

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id,
                    'status' => $status,
                ]);

                return $this->buildWithdrawalSuccessResponse($order);
            }

            // 只有成功時才驗證金額是否匹配
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $input['amount'], $payoutRecord);
            }

            // 失敗不會返回 successTime
            $transactionTime = isset($input['successTime'])
                ? Carbon::parse($input['successTime'], 'Asia/Taipei')->toSystemTime()->toDateTimeString()
                : null;

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: (string) $input['amount'],
                resultCode: $status,
                transactionTime: $transactionTime,
                sign: $input['sign']
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' 代付回調處理異常', [
                'input' => $this->maskSensitiveParams($input),
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
