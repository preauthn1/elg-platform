<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EpicPay;

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
    use EpicPayTrait;

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
        // 憑證未配置時 generateCallbackToken() 的 merchant／secret 段為空、簽名可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        if ($this->findMissingCredentials(['MERCHANT_CODE', 'SECRET_KEY'], 'withdrawal callback') !== []) {
            throw new ApiException('Signature verification failed');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'id' => 'required|string',
            'status' => 'required|string',
            'amount' => 'required|string',
            'fee' => 'required|string',
            'message' => 'required|string',
            'token' => 'required|string',
            'ts' => 'required|string',
        ]);

        // 驗證簽名
        $token = [REDACTED], $this->info['MERCHANT_CODE'], $this->info['SECRET_KEY']);

        if ($token !== $input['token']) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）
            $payoutRecord = $this->findAndLockPayoutRecord($input['id']);

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
                        'transaction_no' => $input['id'],
                    ]
                );
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                return $this->handleWithdrawalOrderException(
                    exception: $e,
                    order: $order,
                    orderNo: $input['id'],
                );
            }

            // doc-literal 二分解析（依 EpicPay 文檔終態定義）：
            // '1'=代付成功（終態）→ SUCCESS
            // 其他=代付失敗（終態）→ FAILURE，標 29
            // 注意：EpicPay 無任何非終態碼，無 INDETERMINATE 路徑
            $outcome = $input['status'] === '1'
                ? WithdrawalCallbackOutcome::SUCCESS
                : WithdrawalCallbackOutcome::FAILURE;

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
                transactionTime: now()->toDateTimeString(),
                sign: $input['token']
            );

            // 更新訂單狀態並處理交易日誌
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

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
