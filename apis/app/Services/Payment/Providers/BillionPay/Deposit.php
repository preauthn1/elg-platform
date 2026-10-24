<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BillionPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Arr;

class Deposit extends AbstractMethod
{
    use BillionPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /** @noinspection SpellCheckingInspection */
    /**
     * @throws ApiException
     */
    public function handle(DepositRequestData $request): DepositResponseData
    {
        $this->login();

        $order = $request->order;
        $params = $this->buildDepositParams($request);

        $result = $this->sendRequest($this->info['DEPOSIT_URL'], $params);

        // 檢查響應狀態 - 嚴格判斷：必須 code === 1 才視為成功
        if (!Arr::has($result, ['internal_id', 'qr'])) {
            $errorMessage = $result['message'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        // 紀錄三方訂單號
        $order->update(['third_party_order_no' => $result['internal_id']]);

        return DepositResponseData::from([
            'type' => DepositResponseType::QR_CODE_IMAGE_PATH,
            'url' => $result['qr'],
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['internal_id'],
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
            'amount' => (string) $order->amount,
            'callback_url' => $callbackUrl,
            'currency' => $this->info['CURRENCY'] ?? '',
            'customer_id' => $member->username,
            'full_name' => $member->username,
            'method' => 'card',
            'transaction_id' => $order->order_no,
        ];

        $params[$this->signField] = $this->generateSign($params, $this->info['SECRET_KEY']);

        return $params;
    }
}
