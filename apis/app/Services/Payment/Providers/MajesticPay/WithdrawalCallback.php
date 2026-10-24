<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MajesticPay;

use App\Enums\General\HttpCode;
use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WithdrawalCallback extends AbstractMethod
{
    use MajesticPayTrait;

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
        // 憑證未配置時簽名的 secret／key 段為空、簽名可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['SECRET_KEY'], 'withdrawal callback') !== []) {
            return $this->buildFailResponse('Invalid signature');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'status' => 'required|string',
            'currency' => 'required|string',
            'amount' => 'required|string',
            'hash' => 'nullable|string',
            'ref_no' => 'required|string',
            'platform_charge' => 'required|string',
            'transaction_reference' => 'required|string',
            'updated_at' => 'required|string',
            'is_sandbox' => 'required|integer|in:0,1',
        ]);

        // 驗證簽名
        if (!$this->checkHash($input, $this->info['SECRET_KEY'])) {
            return $this->buildFailResponse('Invalid signature');
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）- MajesticPay 使用 third_party_transaction_no 和 transaction_no 雙重匹配
            $payoutRecord = $this->findAndLockPayoutRecordByThirdPartyNo(
                $input['transaction_reference'],
                $input['ref_no']
            );

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->buildFailResponse('Payout record not found');
            }

            // 代付請求記錄已處理 (重複回調)
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->buildSuccessResponse();
            }

            // 查詢訂單（加鎖）
            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->buildFailResponse('Withdrawal order not found');
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'orderNo' => $input['ref_no'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                // 已處理過的訂單：返回成功避免重複回調
                if ($e->getCode() === HttpCode::HTTP_OK->value) {
                    return $this->buildSuccessResponse();
                }

                return $this->buildFailResponse($e->getMessage());
            }

            // status: completed=成功, pending=中間態（保持22）, 其他=失敗
            $status = Str::lower($input['status']);
            $outcome = match (true) {
                $status === 'completed' => WithdrawalCallbackOutcome::SUCCESS,
                $status === 'pending' => WithdrawalCallbackOutcome::INDETERMINATE,
                default => WithdrawalCallbackOutcome::FAILURE,
            };

            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();
                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'orderNo' => $input['ref_no'],
                    'status' => $status,
                ]);

                return $this->buildSuccessResponse($order);
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
                transactionTime: Carbon::parse($input['updated_at'])->toDateTimeString(),
                sign: $input['hash']
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->buildSuccessResponse($order);

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
     * 建構成功響應
     */
    private function buildSuccessResponse(?WithdrawalOrder $order = null): WithdrawalCallbackResponseData
    {
        return WithdrawalCallbackResponseData::from([
            'response' => 'OK',
            'order' => $order,
            'httpStatusCode' => HttpCode::HTTP_OK->value,
        ]);
    }

    /**
     * 建構失敗響應
     */
    private function buildFailResponse(string $message, ?WithdrawalOrder $order = null): WithdrawalCallbackResponseData
    {
        return WithdrawalCallbackResponseData::from([
            'response' => 'Failed',
            'errorMessage' => $message,
            'order' => $order,
            'httpStatusCode' => HttpCode::HTTP_BAD_REQUEST->value,
        ]);
    }
}
