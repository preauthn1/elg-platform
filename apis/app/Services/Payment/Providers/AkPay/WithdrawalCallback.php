<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AkPay;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalCallback extends AbstractMethod
{
    use AkPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    // 定義返回三方的信息
    protected string|array $notifySuccessText = [
        'success' => true,
    ];

    protected string|array $notifyFailText = [
        'success' => false,
    ];

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        // 憑證未配置時 generateSecretKey() 的 key 段為空、簽名可離線計算，
        // 攻擊者可偽造回調洗訂單或解鎖在途重送 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤的例外相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['MERCHANT_TOKEN'], 'withdrawal callback') !== []) {
            throw new ApiException('Signature verification failed');
        }

        // 驗證請求參數
        $input = $request->validate([
            'source_id' => 'required|string',
            'tx_id' => 'required|string',
            'status' => 'required|string',
            'bank_code' => 'required|string',
            'amount' => 'required|string',
            'currency' => 'required|string',
            'secret_key' => 'required|string',
        ]);

        // 驗證簽名
        if (!$this->checkSecretKey($input, $this->info['MERCHANT_TOKEN'])) {
            throw new ApiException('Signature verification failed');
        }

        $orderNo = str_replace($this->info['MERCHANT_CODE'] . '_', '', $input['source_id']);
        $orderStatus = $input['status'];
        $orderAmount = Common::numAdd('0', (string) $input['amount'], 4);
        $orderSign = $input['secret_key'];

        try {
            DB::beginTransaction();

            // 查詢代付請求記錄（加鎖）- 使用 Trait 方法
            $payoutRecord = $this->findAndLockPayoutRecord($orderNo);

            if (!$payoutRecord) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse();
            }

            // 代付請求記錄已處理 - 使用 Trait 方法
            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();

                return $this->buildWithdrawalSuccessResponse();
            }

            // 查詢訂單（加鎖）- 使用 Trait 方法
            $order = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            if (!$order) {
                DB::rollBack();

                return $this->buildWithdrawalFailResponse();
            }

            try {
                // 檢查訂單狀態
                $this->checkWithdrawalOrderStatus($order);
            } catch (WithdrawalOrderException $e) {
                DB::rollBack();

                // 200 表示訂單已處理完成，回傳成功訊息給三方
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

            // doc-literal 二分解析（依 AkPay 文檔終態定義）：
            // Approved=代付成功（終態）→ SUCCESS
            // 其他（Rejected / Failed / 任何未知碼）=代付失敗（終態）→ FAILURE，標 29
            // 注意：AkPay 無任何非終態碼，無 INDETERMINATE 路徑
            $outcome = $orderStatus === 'Approved'
                ? WithdrawalCallbackOutcome::SUCCESS
                : WithdrawalCallbackOutcome::FAILURE;

            // 只有成功時才驗證金額是否匹配（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($order, $orderAmount, $payoutRecord);
            }

            // 更新代付請求記錄
            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
                resultAmount: $orderAmount,
                resultCode: $orderStatus,
                transactionTime: now()->toDateTimeString(),
                sign: $orderSign
            );

            // 更新訂單狀態並處理交易日誌 - 使用 Trait 方法
            $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);

            DB::commit();

            // 記錄處理日誌 - 使用 Trait 方法
            $this->logCallbackProcessed($payoutRecord, $order, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            // 返回成功響應 - 使用 Trait 方法
            return $this->buildWithdrawalSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' callback exception', [
                'input' => $input,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
