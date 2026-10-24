<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TrustPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

/**
 * @knowledge rules/currency-and-wallet-currency
 */
class Withdrawal extends AbstractMethod
{
    use TrustPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'TrustPay';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        $apiUrl = $this->info['API_URL'] ?? null;
        $apiKey = [REDACTED]'API_KEY'] ?? null;
        $secretKey = $this->info['SECRET_KEY'] ?? null;

        if (!$apiUrl || !$apiKey || !$secretKey) {
            return $this->createFailureResponse('TrustPay credentials not configured');
        }

        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);

        // 參數組建（含 currency 查詢與簽名）屬 pre-flight：失敗視為資料/設定問題，不建立記錄
        try {
            $currency = $this->getCurrencyCode($withdrawalOrder->bank?->currency_id);
            $amount = Common::numAdd('0', (string) $withdrawalOrder->amount, 2);
            $params = [
                'api_key' => $apiKey,
                'ref_no' => $transactionNo,
                'amount' => $amount,
                'currency' => $currency,
                'hash' => $this->generateRequestSignature($transactionNo, $amount, $currency, $secretKey),
                'callback_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
                'bank_name' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
                'bank_holder_name' => $withdrawalOrder->account_name,
                'bank_account' => $withdrawalOrder->account_no,
                'player_id' => (string) $withdrawalOrder->owner->id,
            ];
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $url = rtrim((string) $apiUrl, '/') . '/api/create_payout';

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest($url, $params),
            interpretResult: function (array $response): PayoutResult {
                if (($response['status'] ?? null) !== true) {
                    return new PayoutResult(
                        isSuccess: false,
                        errorMessage: isset($response['message']) && is_string($response['message'])
                            ? $response['message']
                            : 'TrustPay payout request rejected',
                    );
                }

                if (!isset($response['result']) || ($response['result']['status_id'] ?? null) != 0) {
                    return new PayoutResult(isSuccess: false, errorMessage: 'TrustPay error: status_id != 0');
                }

                return new PayoutResult(
                    isSuccess: true,
                    thirdPartyOrderNo: (string) ($response['result']['transaction_reference'] ?? ''),
                );
            },
        );
    }
}
