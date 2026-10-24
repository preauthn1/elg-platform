<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\SurePay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
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
    use SurePayTrait;
    use WithdrawalCallbackTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    // {
    //   "amount": "50.00",
    //   "trxno": "92256687",
    //   "currency": "MYR",
    //   "customer": "rbrbrb",
    //   "status": "-1",
    //   "status_message": "test txn failed",
    //   "token": "6776e9524c2caca1543f1b2b329d40fb",
    //   "merchant": "SPREE009Tm99s",
    //   "refid": "Sure2026022609275754926",
    //   "clientip": "0.0.0.0"
    // }
    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 憑證未配置時 generateCallbackToken() 的 key 段為空、簽名可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        // 對外沿用與token 驗證失敗相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['CALLBACK_API_KEY'], 'withdrawal callback') !== []) {
            return $this->buildWithdrawalFailResponse();
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'amount' => 'required|string',
            'trxno' => 'required|string',
            'currency' => 'required|string',
            'customer' => 'required|string',
            'status' => 'required|string',
            'status_message' => 'required|string',
            'token' => 'required|string',
            'merchant' => 'required|string',
            'refid' => 'required|string',
            'clientip' => 'required|string',
        ]);

        // 驗證 token 是否匹配
        if (!$this->checkToken($input)) {
            Log::warning($this->getDriverName() . ' callback token verification failed');

            return $this->buildWithdrawalFailResponse();
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['refid']);

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

                return $this->buildWithdrawalFailResponse(
                    logMessage: 'callback order not found',
                    logContext: [
                        'withdrawal_order_id' => $payoutRecord->withdrawal_order_id,
                        'transaction_no' => $input['trxno'],
                    ]
                );
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                return $this->handleWithdrawalOrderException($e, $order, $input['refid']);
            }

            $status = $input['status'];
            $outcome = $this->resolveWithdrawalOutcome($status, ['1'], ['-1']);

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $order->id, 'status' => $status,
                ]);

                return $this->buildWithdrawalSuccessResponse($order);
            }

            // 只有成功時才驗證金額是否匹配
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $input['amount'], $payoutRecord);
            } else {
                Log::warning($this->getDriverName() . ' callback indicates payout failure', [
                    'order_no' => $input['refid'],
                    'status' => $input['status'],
                    'status_message' => $input['status_message'],
                ]);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $input['amount'],
                resultCode: $input['status'],
                transactionTime: now()->toDateTimeString(),
                sign: $input['token']
            );

            // 更新提款訂單狀態
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' withdrawal callback exception', [
                'order_no' => $input['refid'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
