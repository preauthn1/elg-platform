<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GlobePay;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WithdrawalQuery extends AbstractMethod
{
    use GlobePayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理三方反查代付訂單請求
     */
    public function handle(Request $request): array
    {
        $requestData = $request->all();

        try {
            // 驗證必要欄位
            if (!isset($requestData['Data']) || !isset($requestData['Signature'])) {
                throw new ApiException('Missing required query parameters');
            }

            // 驗證 RSA 簽名
            $isValidSignature = $this->verifyRsaSignature(
                $requestData['Data'],
                $requestData['Signature'],
                $this->info[self::FIELD_RSA_PUBLIC_KEY]
            );

            if (!$isValidSignature) {
                throw new ApiException('Signature verification failed');
            }

            // 解密 Data
            $decryptedData = $this->aesDecrypt($requestData['Data'], $this->info[self::FIELD_AES_KEY]);
            $data = json_decode($decryptedData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException('Failed to parse callback data');
            }

            if (!isset($data['MerchantTransactionId'])) {
                throw new ApiException('Invalid request data: missing MerchantTransactionId');
            }

            if (!isset($data['Amount'])) {
                throw new ApiException('Invalid request data: missing Amount');
            }

            if (!isset($data['CurrencyCode'])) {
                throw new ApiException('Invalid request data: missing CurrencyCode');
            }

            $merchantTransactionId = $data['MerchantTransactionId'];
            $orderAmount = $data['Amount'];
            $currencyCode = $data['CurrencyCode'];

            Log::info($this->getDriverName() . ' query request', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'merchant_transaction_id' => $merchantTransactionId,
                'data' => $data,
            ]);

            // 查詢代付記錄
            $payoutRecord = WithdrawalPayoutRecord::query()
                ->where('transaction_no', $merchantTransactionId)
                ->where('agent_cash_flow_id', $this->agentCashFlow->id)
                ->with(['withdrawalOrder'])
                ->first();

            if (!$payoutRecord) {
                Log::warning($this->getDriverName() . ' query: transaction not found', [
                    'agent_cash_flow_id' => $this->agentCashFlow->id,
                    'merchant_transaction_id' => $merchantTransactionId,
                ]);

                return [
                    'response' => 'failed',
                    'httpStatusCode' => 404,
                ];
            }

            // 驗證金額是否吻合
            $recordAmount = $payoutRecord->result_amount ?? $payoutRecord->withdrawalOrder->amount ?? 0;
            $queryAmount = floatval($orderAmount);

            if (abs(floatval($recordAmount) - $queryAmount) > 0.01) {
                Log::warning($this->getDriverName() . ' query: amount mismatch', [
                    'agent_cash_flow_id' => $this->agentCashFlow->id,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'record_amount' => $recordAmount,
                    'query_amount' => $queryAmount,
                ]);

                return [
                    'response' => 'failed',
                    'httpStatusCode' => 400,
                ];
            }

            // 驗證幣別是否吻合
            if ($currencyCode !== $this->info[self::FIELD_CURRENCY]) {
                Log::warning($this->getDriverName() . ' query: currency mismatch', [
                    'agent_cash_flow_id' => $this->agentCashFlow->id,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'expected_currency' => $this->info[self::FIELD_CURRENCY],
                    'query_currency' => $currencyCode,
                ]);

                return [
                    'response' => 'failed',
                    'httpStatusCode' => 400,
                ];
            }

            Log::info($this->getDriverName() . ' query: validation passed', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'merchant_transaction_id' => $merchantTransactionId,
                'amount' => $recordAmount,
                'currency' => $currencyCode,
            ]);

            // 返回成功（純文字）
            return [
                'response' => 'success',
                'httpStatusCode' => 200,
            ];
        } catch (ApiException $e) {
            Log::error($this->getDriverName() . ' query error', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'error' => $e->getMessage(),
                'request' => $requestData,
            ]);

            return [
                'response' => 'failed',
                'httpStatusCode' => 400,
            ];
        } catch (\Throwable $e) {
            Log::error($this->getDriverName() . ' query exception', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'error' => $e->getMessage(),
                'request' => $requestData,
            ]);

            return [
                'response' => 'failed',
                'httpStatusCode' => 500,
            ];
        }
    }
}
