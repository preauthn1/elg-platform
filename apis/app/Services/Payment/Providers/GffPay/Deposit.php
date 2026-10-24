<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GffPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Http;

class Deposit extends AbstractMethod
{
    protected AgentCashFlow $agentCashFlow;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);
        $params['sign'] = $this->sign($params, $this->info['MerchantKey']);

        $url = trim($this->info['APIUrl'], '/') . '/deposit-order';
        $result = $this->sendRequest($url, $params);

        // 檢查響應狀態 - 嚴格判斷：必須 code === 200 才視為成功
        if (!isset($result['code']) || $result['code'] !== 200) {
            $errorMessage = $result['message'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['data']['payment_url'],
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['data']['order_no'],
        ]);
    }

    /**
     * 構建代收請求參數
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;
        $orderType = $order->orderType;

        return [
            'currency_code' => $orderType->key,
            'merchant_code' => $this->info['MerchantNo'],
            'merchant_order_no' => $order->order_no,
            'amount' => $order->amount,
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
        ];
    }

    protected function sendRequest(string $url, array $params): array
    {
        $result = Http::logAction('GffPay_Deposit')
            ->post($url, $params)
            ->throw()
            ->json();

        return $result;
    }

    private function sign(array $input, string $secretKey): string
    {
        unset($input['sign']);
        ksort($input);
        $signString = '';
        foreach ($input as $k => $v) {
            $signString .= $k . '=' . $v . '&';
        }
        $signString = rtrim($signString, '&');
        $signString .= $secretKey;
        $sign = strtoupper(md5($signString));

        return $sign;
    }
}
