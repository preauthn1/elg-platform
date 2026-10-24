<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TopPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

class Deposit extends AbstractMethod
{
    use TopPayTrait;

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
        $result = $this->sendRequest('/pay/prePay', $params);

        if (!isset($result['code']) || $result['code'] !== '9999') {
            $errorMessage = $result['msg'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        $data = $result['data'] ?? [];
        $cashierUrl = $data['cashierUrl'] ?? '';

        if ($cashierUrl === '') {
            throw new ApiException($this->getDriverName() . ' deposit error: missing cashierUrl in response');
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $cashierUrl,
            'orderNo' => $order->order_no,
            'providerOrderNo' => $data['platOrderNum'] ?? '',
        ]);
    }

    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;

        $params = [
            'mchNo' => $this->info[$this->merchantCodeField],
            'orderNum' => $order->order_no,
            'amount' => (int) $order->amount,
            'productDetail' => 'Deposit',
            'method' => $order->orderType->key ?? '',
            'timestamp' => (string) now()->getTimestampMs(),
            'customerName' => $member->username,
            // TopPay 要求 customerEmail / customerPhone 必填且需通過格式驗證，
            // 但本對接不依賴會員真實資料（避免空值或格式錯誤導致 0001），改用固定值送出。
            'customerEmail' => config('custom.site_name') . '@example.invalid',
            'customerPhone' => '081234567890',
            'expiryPeriod' => 1440,
            'downNotifyUrl' => $request->callbackUrl,
            'redirectUrl' => $request->returnUrl,
        ];

        $params['sign'] = $this->generateSign($params);

        return $params;
    }
}
