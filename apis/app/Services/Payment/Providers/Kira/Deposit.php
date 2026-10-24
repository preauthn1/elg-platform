<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Kira;

use App\Enums\DepositResponseType;
use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\OrderType;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use KiraTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $params): DepositResponseData
    {
        $order = $params->order;
        $member = $params->member;

        // 檢查必要配置
        $requiredParams = ['API_URL', 'CLIENT_ID', 'CLIENT_SECRET', 'CLIENT_PRIVATE_KEY', 'CLIENT_SYMMETRIC_KEY', 'SERVER_PUBLIC_KEY'];
        foreach ($requiredParams as $param) {
            if (empty($this->info[$param])) {
                throw new ApiException(sprintf('%s %s not configured', $this->getDriverName(), $param));
            }
        }

        $orderType = $order->orderType;
        $currency = $this->getCurrency($orderType);
        $paymentMethod = $orderType->key;

        // 組建請求參數
        $requestData = [
            'order' => [
                'id' => $order->order_no,
                'additionalData' => '',
                'title' => 'Deposit',
                'amount' => sprintf('%.2f', $order->amount),
                'currencyType' => $currency,
                'logoUrl' => '',
                'redirectUrl' => $params->returnUrl,
                'callbackUrl' => Common::depositCallbackUrl($this->agentCashFlow->id),
            ],
            'customer' => [
                'name' => $member->username,
                'phone' => $member->mobile ?? '',
                'email' => trim($member->email ?? '') ?: 'user@example.invalid',
            ],
            'method' => $paymentMethod,
        ];

        // 記錄請求
        $traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        Log::info($this->getDriverName() . ' Deposit Request', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'amount' => $order->amount,
            'currency' => $currency,
            'method' => $paymentMethod,
            'trace_id' => $traceId,
        ]);

        try {
            // 發送請求
            $result = $this->sendRequest('/gateway/v1/createPayment', $requestData);

            Log::debug($this->getDriverName() . ' Deposit Response', [
                'order_no' => $order->order_no,
                'result' => $result,
            ]);

            // 解析回應
            $paymentUrl = $result['data']['paymentUrl'] ?? null;
            $transactionId = (string) ($result['data']['transactionId'] ?? '');

            if (empty($paymentUrl)) {
                throw new ApiException(sprintf('%s deposit error: paymentUrl not found in response', $this->getDriverName()));
            }

            // 更新訂單記錄
            $order->update([
                'third_party_order_no' => $transactionId,
                'remark' => Common::safeJsonEncode([
                    'trace_id' => $traceId,
                    'request' => $this->maskSensitiveData($requestData),
                    'response' => $result,
                ]),
            ]);

            Log::info($this->getDriverName() . ' Deposit Success', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'transaction_id' => $transactionId,
                'payment_url' => $paymentUrl,
            ]);

            return DepositResponseData::from([
                'type' => DepositResponseType::URL_REDIRECT,
                'url' => $paymentUrl,
                'html' => null,
                'orderNo' => $order->order_no,
                'providerOrderNo' => $transactionId,
            ]);
        } catch (ApiException $e) {
            Log::error($this->getDriverName() . ' Deposit Error', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'error' => $e->getMessage(),
            ]);

            // 更新訂單備註
            $order->update([
                'remark' => Common::safeJsonEncode([
                    'trace_id' => $traceId,
                    'request' => $this->maskSensitiveData($requestData),
                    'error' => $e->getMessage(),
                ]),
            ]);

            throw $e;
        }
    }

    /**
     * 取得對應的 Currency 代碼
     */
    private function getCurrency(OrderType $orderType): string
    {
        return $orderType->currency->code;
    }
}
