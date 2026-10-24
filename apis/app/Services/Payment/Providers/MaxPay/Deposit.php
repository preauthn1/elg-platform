<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MaxPay;

use App\Enums\DepositResponseType;
use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Logging\HttpLoggerCustom;
use App\Models\AgentCashFlow;
use App\Models\OrderType;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use MaxPayTrait;

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

        $orderType = $order->orderType;
        $currency = $this->getCurrency($orderType);
        $paymentMethod = $orderType->key;
        $requestData = [
            'api_key' => $this->info['API_KEY'],
            'ref_no' => $order->order_no,
            'amount' => $order->amount,
            'payment_method_id' => $paymentMethod,
            'currency' => $currency,
            'hash' => $this->generateHash($this->info['API_KEY'], $order->order_no, $order->amount, $this->info['SECRET_KEY']),
            'callback_url' => $params->callbackUrl,
            'return_url' => $params->returnUrl,
        ];

        $traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        Log::info('MaxPay 代收請求', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'amount' => $order->amount,
            'currency' => $currency,
            'method' => $paymentMethod,
            'trace_id' => $traceId,
        ]);

        $url = rtrim((string) $this->info['API_URL'], '/') . '/api/create_order';

        // HTTP 狀態與對端 IP 取的是「最後一次出站」，送出前先清掉殘留（見 HttpLoggerCustom）
        HttpLoggerCustom::forgetLastOutbound();

        try {
            $result = $this->sendRequest('/api/create_order', $requestData);

            Log::debug('MaxPay 代收回應', [
                'order_no' => $order->order_no,
                'result' => $result,
            ]);

            $paymentUrl = $result['data']['payment_link'] ?? null;
            $transactionId = (string) ($result['data']['transaction_reference'] ?? '');

            if (empty($paymentUrl)) {
                $this->logExchange(
                    action: OrderRequestActionEnum::DEPOSIT_CREATE,
                    url: $url,
                    request: $requestData,
                    response: $result,
                    result: OrderRequestResultEnum::NO_URL,
                    order: $order,
                    sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
                    httpStatus: HttpLoggerCustom::lastOutboundHttpStatus(),
                );

                throw new ApiException('MaxPay deposit error: paymentUrl not found in response');
            }

            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CREATE,
                url: $url,
                request: $requestData,
                response: $result,
                result: OrderRequestResultEnum::OK,
                order: $order,
                thirdPartyOrderNo: $transactionId !== '' ? $transactionId : null,
                sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
                httpStatus: HttpLoggerCustom::lastOutboundHttpStatus(),
            );

            $order->update([
                'third_party_order_no' => $transactionId,
                'remark' => json_encode([
                    'trace_id' => $traceId,
                    'request' => $requestData,
                    'response' => $result,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            Log::info('MaxPay 代收成功', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'transaction_id' => $transactionId,
            ]);

            return DepositResponseData::from([
                'type' => DepositResponseType::URL_REDIRECT,
                'url' => $paymentUrl,
                'html' => null,
                'orderNo' => $order->order_no,
                'providerOrderNo' => $transactionId,
            ]);
        } catch (ApiException $e) {
            // paymentUrl 缺漏那條已經記過；這裡補的是 sendRequest 連不上／三方回非 2xx 的情形
            if (!str_contains($e->getMessage(), 'paymentUrl not found')) {
                $this->logExchange(
                    action: OrderRequestActionEnum::DEPOSIT_CREATE,
                    url: $url,
                    request: $requestData,
                    response: $e->getMessage(),
                    result: OrderRequestResultEnum::EXCEPTION,
                    order: $order,
                    sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
                    httpStatus: $this->httpStatusOf($e) ?? HttpLoggerCustom::lastOutboundHttpStatus(),
                );
            }

            Log::error('MaxPay 代收失敗', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'error' => $e->getMessage(),
            ]);

            $order->update([
                'remark' => json_encode([
                    'trace_id' => $traceId,
                    'request' => $requestData,
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            throw $e;
        }
    }

    private function getCurrency(OrderType $orderType): string
    {
        return $orderType->currency->code;
    }
}
