<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

class Deposit extends AbstractMethod
{
    use FPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * @see  https://doc.fpay.support/cn/#api_generate_order
     */
    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);
        $result = $this->sendRequest('/merchant/generate_orders', $params);

        // 檢查響應狀態 - 嚴格判斷：必須 status === true 才視為成功
        if (!isset($result['status']) || $result['status'] !== true) {
            $errorMessage = $result['message'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['p_url'] ? urldecode($result['p_url']) : '',
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
        $returnUrl = $request->returnUrl;

        return [
            'username' => $member->username,
            'auth' => $this->getAuthCode(),
            'amount' => $order->amount,
            'currency' => $this->agentCashFlow->currency->code,
            'redirect_url' => $returnUrl,
            'orderid' => $order->order_no,
            'bank_code' => $order->orderType->key ?? '',
        ];
    }
}
