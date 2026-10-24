<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MajesticPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

class Deposit extends AbstractMethod
{
    use MajesticPayTrait;

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
        $params['hash'] = $this->generateHash($params, $this->info['SECRET_KEY']);

        $result = $this->sendRequest('/api/create_deposit', $params);

        // 檢查響應狀態 - 嚴格判斷：必須 status === true 才視為成功
        if (!isset($result['status']) || $result['status'] !== true) {
            $errorMessage = $result['message'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        // 紀錄三方訂單號
        $order->update(['third_party_order_no' => $result['data']['transaction_reference']]);

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['data']['payment_link'] ?: '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['data']['transaction_reference'] ?: '',
        ]);
    }

    /**
     * 構建代收請求參數
     *
     * payment_method_id:
     * - Online Banking = 3
     * - DuitNow = 6
     * - TNG = 6
     * - GrabPay = 6
     * - Boost = 6
     * - Shopee Pay = 6
     * - Telco Pin = 8
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;

        return [
            'api_key' => $this->info['API_KEY'],
            'ref_no' => $order->order_no,
            'amount' => $order->amount,
            'payment_method_id' => $order->orderType->key,
            'currency' => $this->info['CURRENCY'],
            'return_url' => $returnUrl,
            'callback_url' => $callbackUrl,
            'player_id' => $member->id,
        ];
    }
}
