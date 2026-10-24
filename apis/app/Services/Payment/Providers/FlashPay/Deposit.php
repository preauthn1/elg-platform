<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FlashPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

class Deposit extends AbstractMethod
{
    use FlashPayTrait;

    protected array $info;

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);
        $params['tran_signature'] = $this->generateSign($params, $this->info['SECRET_KEY']);

        $result = $this->sendRequest('/payin', $params);

        if (!isset($result['status_code']) || $result['status_code'] !== 0) {
            throw new ApiException(
                sprintf('%s deposit error: %s', $this->getDriverName(), $result['status_msg'] ?? 'Unknown error')
            );
        }

        $order->update(['third_party_order_no' => $result['tran_token'] ?? '']);

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['redirect_to'] ?? '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['tran_token'] ?? '',
        ]);
    }

    private function buildDepositParams(DepositRequestData $request): array
    {
        $order = $request->order;

        return [
            'client_id' => $this->info['CLIENT_ID'],
            'currency' => $this->info['CURRENCY'],
            'cont_code' => $order->orderType->key,
            'tran_customer' => $request->member->username,
            'tran_amount' => (int) Common::numFloor($order->amount, 0),
            'tran_unique' => $order->order_no,
            'tran_callback_url' => $request->callbackUrl,
            'tran_return_url_success' => $request->returnUrl,
        ];
    }
}
