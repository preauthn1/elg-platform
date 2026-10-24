<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\A3Pay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A3Pay Payout Verification（api.md）：三方出款前打 Submit Payout 帶的 ReturnClientURL 向我方確認單據，
 * 回純文字 success 才放款，回其他任何內容即拒絕。
 *
 * 信封：MerchantCode／Data（AES）／AdditionalInformationData／Signature；Data 解密後含 MerchantTransactionId／Amount／CurrencyCode。
 * 驗簽對象是解密後的 JSON 明文（api.md「Verify Signature」）。
 *
 * 這是三方放款前唯一會問我方的一次，也是擋「過期交易號」的唯一閘門，回 success 等同授權出款。核對順序：
 * 1. 代付記錄存在於本商戶。
 * 2. 記錄是該訂單最新一筆：21 卡單經人工轉 29 再重新發起後，舊記錄仍在（PENDING 或 REQUEST_FAILED）、
 *    訂單又回到 22，舊交易號若放行就是兩筆都出款。
 * 3. 記錄狀態必須是 PENDING(0)：-2 是我方已宣告未建單、允許重送；-1 是我方逾時結果未知，此時回 failed
 *    等於讓三方不出款、把「未知」變成確定不付，訂單留在 21 交排程與人工收；-3／1 已定案。
 * 4. 訂單狀態走與回調同一個統一入口 checkWithdrawalOrderStatus()：只放行 21／22，29 拒絕並送防重複出款告警。
 * 5. 金額與記錄相符、幣別與商戶配置相符。
 * 任一不符回 failed；這裡是唯讀，不改訂單與記錄。
 *
 * @knowledge rules/provider-a3pay
 */
class WithdrawalQuery extends AbstractMethod
{
    use A3PayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * @return array{response: string, httpStatusCode: int}
     */
    public function handle(Request $request): array
    {
        $requestData = $request->all();

        try {
            if (!isset($requestData['Data'], $requestData['Signature'])) {
                throw new ApiException('Missing required query parameters');
            }

            $decryptedData = $this->aesDecrypt($requestData['Data'], $this->info[self::FIELD_AES_KEY]);

            if (!$this->verifyRsaSignature($decryptedData, $requestData['Signature'], $this->info[self::FIELD_RSA_PUBLIC_KEY])) {
                throw new ApiException('Signature verification failed');
            }

            $data = json_decode($decryptedData, true);

            if (!is_array($data)) {
                throw new ApiException('Failed to parse query data');
            }

            if (!isset($data['MerchantTransactionId'], $data['Amount'], $data['CurrencyCode'])) {
                throw new ApiException('Invalid query data: missing MerchantTransactionId, Amount or CurrencyCode');
            }

            $merchantTransactionId = (string) $data['MerchantTransactionId'];

            Log::info($this->getDriverName() . ' payout verification request', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'merchant_transaction_id' => $merchantTransactionId,
                'data' => $data,
            ]);

            $payoutRecord = WithdrawalPayoutRecord::query()
                ->where('transaction_no', $merchantTransactionId)
                ->where('agent_cash_flow_id', $this->agentCashFlow->id)
                ->with(['withdrawalOrder'])
                ->first();

            if (!$payoutRecord) {
                return $this->reject('transaction not found', $merchantTransactionId, 404);
            }

            $hasNewerRecord = WithdrawalPayoutRecord::query()
                ->where('withdrawal_order_id', $payoutRecord->withdrawal_order_id)
                ->where('id', '>', $payoutRecord->id)
                ->exists();

            if ($hasNewerRecord) {
                return $this->reject('superseded by a newer payout attempt', $merchantTransactionId, 400, [
                    'record_id' => $payoutRecord->id,
                ]);
            }

            if ($payoutRecord->is_success_payout !== WithdrawalPayoutStatusEnum::PENDING) {
                return $this->reject('payout record not pending', $merchantTransactionId, 400, [
                    'record_id' => $payoutRecord->id,
                    'record_status' => $payoutRecord->is_success_payout->value,
                ]);
            }

            try {
                // 與代付回調同一個統一入口：只放行 21／22，29 拒絕並送防重複出款告警
                $this->checkWithdrawalOrderStatus($payoutRecord->withdrawalOrder);
            } catch (WithdrawalOrderException $e) {
                return $this->reject('order status not allowed: ' . $e->getMessage(), $merchantTransactionId, 400, [
                    'order_id' => $payoutRecord->withdrawal_order_id,
                    'order_status' => $payoutRecord->withdrawalOrder?->status,
                ]);
            }

            // createPayoutRecord 建檔時 result_amount 即為訂單金額；外部數值入境先正規化再比對
            $recordAmount = Common::numAdd('0', (string) ($payoutRecord->result_amount ?? $payoutRecord->withdrawalOrder?->amount ?? 0), 4);
            $queryAmount = Common::numAdd('0', (string) $data['Amount'], 4);

            if (Common::numCompare($recordAmount, $queryAmount, 2) !== 0) {
                return $this->reject('amount mismatch', $merchantTransactionId, 400, [
                    'record_amount' => $recordAmount,
                    'query_amount' => $queryAmount,
                ]);
            }

            if ((string) $data['CurrencyCode'] !== (string) $this->info[self::FIELD_CURRENCY]) {
                return $this->reject('currency mismatch', $merchantTransactionId, 400, [
                    'expected_currency' => $this->info[self::FIELD_CURRENCY],
                    'query_currency' => $data['CurrencyCode'],
                ]);
            }

            Log::info($this->getDriverName() . ' payout verification passed', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'merchant_transaction_id' => $merchantTransactionId,
                'amount' => $recordAmount,
            ]);

            return ['response' => 'success', 'httpStatusCode' => 200];
        } catch (ApiException $e) {
            Log::error($this->getDriverName() . ' payout verification error', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'error' => $e->getMessage(),
                'request' => Common::maskCredentials($requestData),
            ]);

            return ['response' => 'failed', 'httpStatusCode' => 400];
        } catch (\Throwable $e) {
            Log::error($this->getDriverName() . ' payout verification exception', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return ['response' => 'failed', 'httpStatusCode' => 500];
        }
    }

    /**
     * @return array{response: string, httpStatusCode: int}
     */
    private function reject(string $reason, string $merchantTransactionId, int $httpStatusCode, array $context = []): array
    {
        Log::warning($this->getDriverName() . ' payout verification rejected: ' . $reason, array_merge([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'merchant_transaction_id' => $merchantTransactionId,
        ], $context));

        return ['response' => 'failed', 'httpStatusCode' => $httpStatusCode];
    }
}
