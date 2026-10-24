<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\QuickPay;

use App\Enums\DepositResponseType;
use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use QuickPayTrait;

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

        $clientId = $this->info['CLIENT_ID'] ?? null;

        if (!$clientId) {
            throw new ApiException('VendorInfo detail is empty, use setVendorInfo() to set the value');
        }

        $depositParams = [
            'uid' => $clientId,
            'orderid' => $order->order_no,
            'channel' => $orderType->key,
            'notify_url' => $params->callbackUrl,
            'return_url' => $params->returnUrl,
            'amount' => $order->amount,
            'userip' => $params->ip,
            'timestamp' => time(),
            'custom' => '',
        ];

        // 移除前後空白字元, 不然會導致簽名失敗
        $depositParams = array_map('trim', $depositParams);
        $depositParams['sign'] = $this->generateSignature($depositParams, $this->info['SECRET_KEY']);

        // 記錄請求
        $traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        Log::info('QuickPay Deposit Request', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'amount' => $order->amount,
            'trace_id' => $traceId,
        ]);

        $response = $this->sendRequest('/pay', $depositParams);

        // 更新訂單記錄
        $order->update([
            'third_party_order_no' => '',
            'remark' => json_encode([
                'trace_id' => $traceId,
                'request' => $depositParams,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if (!isset($response['status']) || $response['status'] != 10000) {
            throw new ApiException($this->resolveStatusMessage((int) ($response['status'] ?? 0)));
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $response['result']['payurl'] ?? '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => (string) ($response['result']['transactionid'] ?? ''),
        ]);
    }
}
