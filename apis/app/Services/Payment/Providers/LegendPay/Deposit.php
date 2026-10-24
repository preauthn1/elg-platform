<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\LegendPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Str;

class Deposit extends AbstractMethod
{
    use LegendPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);
        $headers = $this->buildDepositHeaders($params);

        $result = $this->sendRequest('/LegendPay/payin', $params, $headers);

        // 檢查響應狀態 - 嚴格判斷：必須 code === 1000 才視為成功
        if (!isset($result['code']) || (int) $result['code'] !== 1000) {
            $errorMessage = $result['msg'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        // 檢查響應數據
        if (!isset($result['data']['payUrl'])) {
            throw new ApiException($this->getDriverName() . ' deposit error: missing payment url');
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['data']['payUrl'],
            'orderNo' => $order->order_no,
            'providerOrderNo' => '',
        ]);
    }

    /**
     * 構建代收請求參數
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;

        return [
            'mode' => '0',
            'random' => Str::random(16),
            'orderAmount' => $order->amount,
            'orderId' => $order->order_no,
            'param' => $member->id, // 商户自定义回传参数
            'callbackUrl' => $callbackUrl,
            'returnUrl' => $returnUrl,
            'ts' => (int) (microtime(true) * 1000), // 毫秒时间戳
        ];
    }

    /**
     * 構建代收請求頭
     */
    private function buildDepositHeaders(array $params): array
    {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'l-token' => $this->info['API_TOKEN'],
            'l-sign' => $this->generateSign($params, $this->info),
        ];
    }
}
