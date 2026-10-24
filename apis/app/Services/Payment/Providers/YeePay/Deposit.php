<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\YeePay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

class Deposit extends AbstractMethod
{
    use YeePayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /** @noinspection SpellCheckingInspection */
    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;
        $params = $this->buildDepositParams($request);

        $result = $this->sendRequest($this->info['DEPOSIT_URL'], $params);

        // 檢查響應狀態 - 嚴格判斷：必須 code === 1 才視為成功
        if (!isset($result['code']) || $result['code'] !== 1) {
            $errorMessage = $result['message'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['payurl'] ? urldecode($result['payurl']) : '',
            'orderNo' => $order->order_no,
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
            'uid' => $this->info['MERCHANT_UID'],
            'merchant_num' => $this->info['MERCHANT_NUM'],
            'merchant_order' => $order->order_no,
            'coin' => $order->amount,
            'pay_callbackurl' => $returnUrl,
            'pay_notifyurl' => $callbackUrl,
            'pay_date' => now()->toDateTimeString(),
            'extend' => '',
            'pay_type' => $order->orderType->key, // 9：bank-transfer 8：momo 7：bankQR
            'userinfo' => $member->username,
            'user_ip' => $clientIP,
        ];

        $params['sign'] = $this->generateSign($params, $this->info['API_SECRET']);

        return $params;
    }
}
