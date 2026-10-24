<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

/**
 * BigPayz Payout: POST /Payout/Withdrawal
 * hash = HMAC-SHA256(merchant_code+ref_id+player_username+player_ip+currency_code+amount+bank_code+beneficiary_account+beneficiary_name, API Key).
 */
class Withdrawal extends AbstractMethod
{
    use BigPayzTrait;

    /** error_code => message. If mapped message empty, use response error message. */
    private const CUSTOM_ERROR_MESSAGES = [
        401 => 'Insufficient balance',
    ];

    private static function getCustomErrorMessage(int $errorCode): string
    {
        return \array_key_exists($errorCode, self::CUSTOM_ERROR_MESSAGES)
            ? self::CUSTOM_ERROR_MESSAGES[$errorCode]
            : '';
    }

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'BPZ';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        $orderId = $request->order?->id;
        if ($orderId === null) {
            return $this->createFailureResponse('Withdrawal order or orderId is required');
        }

        try {
            $withdrawalOrder = $this->getWithdrawalOrder($orderId, ['member']);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $config = $this->getConfig();
        $refId = Common::genTransactionNo($this->orderPrefix);
        $currencyCode = $this->agentCashFlow->currency?->code ?? 'MYR';
        $amount = sprintf('%.2f', $withdrawalOrder->amount);
        $playerUsername = $withdrawalOrder->member?->username ?? (string) $withdrawalOrder->member_id;
        $playerIp = request()->ip() ?: '0.0.0.0';
        $bankCode = $this->convertPayoutBankCode($withdrawalOrder->bank_code);

        if ($bankCode === '') {
            return $this->createFailureResponse(
                sprintf('Provider bank code not configured for bank: %s', $withdrawalOrder->bank_code)
            );
        }

        $hash = $this->generatePayoutHash(
            $config->merchantCode,
            $refId,
            $playerUsername,
            $playerIp,
            $currencyCode,
            $amount,
            $bankCode,
            $withdrawalOrder->account_no,
            $withdrawalOrder->account_name,
            $config->apiKey
        );

        $params = [
            'merchant_code' => $config->merchantCode,
            'ref_id' => $refId,
            'player_username' => $playerUsername,
            'player_ip' => $playerIp,
            'currency_code' => $currencyCode,
            'amount' => $amount,
            'bank_code' => $bankCode,
            'beneficiary_account' => $withdrawalOrder->account_no,
            'beneficiary_name' => $withdrawalOrder->account_name,
            'hash' => $hash,
        ];

        $response = $this->processPayout(
            $withdrawalOrder,
            $refId,
            $params,
            performRequest: fn (): array => $this->sendJsonRequest('/Payout/Withdrawal', $params, $config->apiKey, 'payout'),
            interpretResult: function (array $result): PayoutResult {
                $errorCodeValue = (int) ($result['error_code'] ?? -1);
                $errorCodeEnum = BigPayzErrorCodeEnum::fromValue($errorCodeValue);
                $errorMessage = self::getCustomErrorMessage($errorCodeValue) ?: ($result['error_message'] ?? $result['message'] ?? '');

                return new PayoutResult(
                    isSuccess: $errorCodeEnum->isSuccess(),
                    thirdPartyOrderNo: (string) ($result['invoice_number'] ?? ''),
                    errorMessage: $errorMessage,
                );
            },
        );

        // 同步受理：回填三方單號至訂單（保留原行為）
        if ($response->isSuccess && !empty($response->providerOrderNo)) {
            $withdrawalOrder->update(['third_party_order_no' => $response->providerOrderNo]);
        }

        return $response;
    }
}
