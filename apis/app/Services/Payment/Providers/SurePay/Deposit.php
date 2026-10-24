<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\SurePay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

class Deposit extends AbstractMethod
{
    use SurePayTrait;

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
        $this->validateConfig();
    }

    // ! $agentCashFlow->info 應該是這樣的格式
    // {
    //   "API_URL": "https://example.invalid",
    //   "MERCHANT_ID": "Surepay88",
    //   "CONTRACT_NAME": "Surepay88",
    //   "API_KEY": "{API_KEY}",
    //   "CALLBACK_API_KEY": "{CALLBACK_API_KEY}",
    //   "CURRENCY": "MYR"
    // }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);

        $result = $this->sendRequest('/api/request', $params);

        if (!isset($result['status']) || $result['status'] !== 1) {
            $errorMessage = $result['message'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['redirecturl'],
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
        $clientIP = $request->ip;

        $params = [
            'merchant' => $this->info['MERCHANT_ID'],
            'amount' => sprintf('%.2f', $order->amount), // ! 必須要有小數點兩位
            'refid' => $order->order_no,
            'customer' => $member->username,
            'currency' => $this->info['CURRENCY'],
            'language' => $this->info['LANGUAGE'] ?? 'EN', // en,my,th,id,vn
            'clientip' => $clientIP,
            'post_url' => $callbackUrl,
            'failed_return_url' => $returnUrl,
            'return_url' => $returnUrl,
        ];

        $params += $this->buildChannelParams((string) $order->orderType->key);

        $params['token'] = $this->generateToken($params);

        return $params;
    }

    /**
     * 構建指定支付通道的參數
     *
     * 三方規格（v2.22 §4／§5）中 bankcode 與 contractname 是二選一：
     * - bankcode：三方後台建立的通道數字 ID（如 10022198），指定後直跳該通道。
     *   每個商戶獨立分配、不可跨商戶共用，因此只能由 order_types.key 提供。
     * - contractname：未指定 bankcode 時必填，三方依合約通道顯示收銀台選擇頁。
     *
     * 判準取 key 是否為純數字：非數字者（如 OB / QR / Ewallet）不是合法的
     * bankcode，改走 contractname。
     *
     * @return array<string, string>
     */
    private function buildChannelParams(string $orderTypeKey): array
    {
        if (ctype_digit($orderTypeKey)) {
            return ['bankcode' => $orderTypeKey];
        }

        return ['contractname' => $this->info['CONTRACT_NAME']];
    }
}
