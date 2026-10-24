<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TgPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

class Deposit extends AbstractMethod
{
    use TgPayTrait;

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
        $this->validateConfig();
    }

    // ! $agentCashFlow->info 應該是這樣的格式
    // {
    //   "API_URL": "https://example.invalid",
    //   "PUBLIC_KEY": "pk-xxxxxxxxxxxxxxxx",
    //   "SECRET_KEY": "sk-xxxxxxxxxxxxxxxx"
    // }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);

        $result = $this->sendRequest('/transaction/create-payment', $params);

        if (!isset($result['status']) || $result['status'] !== 1) {
            $errorMessage = $result['message'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['data']['checkoutLink'],
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

        $params = [
            'epoch' => now()->timestamp,
            'customer' => [
                'name' => $member->username,
                'email' => config('custom.site_name') . '@example.invalid',
                'phoneNumber' => '60123456789',
            ],
            'order' => [
                'amount' => $order->amount,
                'merchantRefNum' => $order->order_no,
                'redirectUrl' => $returnUrl,
                'notifyUrl' => $callbackUrl,
                'paymentMethod' => $order->orderType->key ?? '',
            ],
        ];

        return $params;
    }
}
