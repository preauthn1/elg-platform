<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TrustPay;

use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Context;

class Deposit extends AbstractMethod
{
    use TrustPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $params): DepositResponseData
    {
        $order = $params->order;
        $orderType = $order->orderType;

        $apiUrl = $this->info['API_URL'] ?? null;
        $apiKey = [REDACTED]'API_KEY'] ?? null;
        $secretKey = $this->info['SECRET_KEY'] ?? null;

        if (!$apiUrl || !$apiKey || !$secretKey) {
            throw new ApiException('VendorInfo detail is empty, use setVendorInfo() to set the value');
        }

        $currency = $this->getCurrencyCode($orderType->currency_id);
        $amount = Common::numAdd('0', (string) $order->amount, 2);

        $postParams = [
            'api_key' => $apiKey,
            'ref_no' => $order->order_no,
            'amount' => $amount,
            'payment_method_id' => (string) $orderType->key,
            'currency' => $currency,
            'hash' => $this->generateRequestSignature($order->order_no, $amount, $currency, $secretKey),
            'callback_url' => $params->callbackUrl,
            'return_url' => $params->returnUrl,
            'player_id' => (string) $params->member->id,
        ];

        $traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);
        $url = rtrim((string) $apiUrl, '/') . '/api/create_deposit';
        $response = $this->sendRequest($url, $postParams);

        $providerOrderNo = (string) ($response['data']['transaction_reference'] ?? '');

        $order->update([
            'third_party_order_no' => $providerOrderNo,
            'remark' => json_encode([
                'trace_id' => $traceId,
                'request' => $postParams,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if (!isset($response['status']) || $response['status'] !== true) {
            throw new ApiException('TrustPay deposit error: ' . ($response['message'] ?? 'Unknown error'));
        }

        return DepositResponseData::from([
            'type' => 1,
            'url' => $response['data']['payment_link'] ?? '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => $providerOrderNo,
        ]);
    }
}
