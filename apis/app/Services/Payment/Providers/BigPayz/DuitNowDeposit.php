<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Log;

/**
 * BigPayz DuitNow deposit: POST /DuitNow/Deposit.
 * Snake_case params, hash = merchant_code + ref_id + player_username + player_ip + currency_code + amount + client_url.
 *
 * @see https://example.invalid/apidocv1.asp#duitnow
 */
class DuitNowDeposit extends AbstractMethod
{
    use BigPayzTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $member = $request->member;
        $order = $request->order;
        $config = $this->getConfig();
        $amount = (string) $order->amount;
        $currency = $this->agentCashFlow->currency?->code;
        if ($currency === null || $currency === '') {
            throw new ApiException(
                sprintf('%s deposit requires a currency code (DuitNow)', $this->getDriverName())
            );
        }
        $refId = $order->order_no;
        $playerIp = $request->ip !== '' ? $request->ip : '0.0.0.0';
        $clientUrl = $request->returnUrl ?: '';

        $hash = $this->generateDuitNowHash(
            $config->merchantCode,
            $refId,
            $member->username,
            $playerIp,
            $currency,
            $amount,
            $clientUrl,
            $config->apiKey
        );

        $params = [
            'merchant_code' => $config->merchantCode,
            'ref_id' => $refId,
            'player_username' => $member->username,
            'player_ip' => $playerIp,
            'currency_code' => $currency,
            'amount' => $amount,
            'client_url' => $clientUrl,
            'hash' => $hash,
            'lang' => 'en',
        ];

        Log::debug(sprintf('%s DuitNow 代收請求', $this->getDriverName()), [
            'order_no' => $order->order_no,
            'url' => $config->getBaseUrlByContext('duitnow') . '/DuitNow/Deposit',
            'params' => $params,
        ]);

        try {
            $result = $this->sendJsonRequest('/DuitNow/Deposit', $params, $config->apiKey, 'duitnow');
        } catch (\Throwable $e) {
            Log::error(sprintf('%s DuitNow 代收請求失敗', $this->getDriverName()), [
                'order_no' => $order->order_no,
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $errorCode = (int) ($result['error_code'] ?? -1);
        if ($errorCode !== 0) {
            $errorMessage = BigPayzPayinErrorCodeEnum::fromValue($errorCode)->label();
            Log::error(sprintf('%s DuitNow 代收失敗（error_code 非 0）', $this->getDriverName()), [
                'order_no' => $order->order_no,
                'response' => $result,
            ]);
            throw new ApiException(
                sprintf('%s deposit error (error_code=%d): %s', $this->getDriverName(), $errorCode, $errorMessage)
            );
        }

        $redirectTo = $this->normalizeRedirectUrl((string) ($result['redirect_to'] ?? ''));
        $token = [REDACTED]'token'] ?? '';
        $invoiceNumber = (string) ($result['invoice_number'] ?? '');
        if ($redirectTo !== '') {
            $order->update(['third_party_order_no' => $invoiceNumber ?: $token]);
        }

        return DepositResponseData::from([
            'type' => 1,
            'url' => $redirectTo,
            'orderNo' => $order->order_no,
            'providerOrderNo' => $invoiceNumber,
        ]);
    }
}
