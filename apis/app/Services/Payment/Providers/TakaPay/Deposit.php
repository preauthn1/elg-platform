<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TakaPay;

use App\Enums\DepositResponseType;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use TakaPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $params): DepositResponseData
    {
        $member = $params->member;
        $order = $params->order;
        $callbackUrl = $params->callbackUrl;
        $returnUrl = $params->returnUrl;
        $clientIP = $params->ip;
        $credentials = $this->getMerchantCredentials();
        $currencyCode = $this->agentCashFlow->currency->code;
        $transactionTime = gmdate('Y-m-d H:i:s');
        $paymentCode = $order->orderType->key ?? '';
        $paymentParts = $this->parsePaymentMethodAndCode($paymentCode);
        $paymentMethod = $paymentParts['paymentMethod'];
        $paymentCode = $paymentParts['paymentCode'];

        $extraRaw = $order->extra_content;
        $extraJson = is_string($extraRaw) ? $extraRaw : ($extraRaw === null ? '' : (string) $extraRaw);
        if ($extraJson !== '') {
            try {
                $decoded = json_decode($extraJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['payment_method'])) {
                    $paymentMethod = (int) $decoded['payment_method'];
                }
            } catch (\JsonException $e) {
                Log::warning('TakaPay 充值異常：extra_content 不是合法 JSON', [
                    'order_no' => $order->order_no ?? null,
                    'order_id' => $order->id ?? null,
                    'agent_cash_flow_id' => $order->agent_cash_flow_id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 構建請求參數（按 API 文檔順序）
        $requestParams = [
            'merchantCode' => $credentials->merchantCode,
            'username' => $member->username,
            'ipAddress' => $clientIP,
            'currencyCode' => $currencyCode,
            'amount' => $order->amount,
            'reference' => $order->order_no,
            'transactionTime' => $transactionTime,
            'returnUrl' => $returnUrl,
            'notifyUrl' => $callbackUrl,
            'paymentMethod' => $paymentMethod,
            'paymentCode' => $paymentCode,
        ];

        // 生成 securityToken
        // MD5(merchantCode + username + ipAddress + currencyCode + amount + reference +
        //     transactionTime + returnUrl + notifyUrl + paymentMethod + paymentCode + secretKey)
        $signFields = [
            $credentials->merchantCode,
            $member->username,
            $clientIP,
            $currencyCode,
            $order->amount,
            $order->order_no,
            $transactionTime,
            $returnUrl,
            $callbackUrl,
            (string) $paymentMethod,
            $paymentCode,
        ];

        $requestParams['securityToken'] = $this->generateSecurityToken($signFields, $credentials->secretKey);

        $result = $this->sendRequest('/deposit', $requestParams);

        // 檢查響應狀態
        $status = (string) ($result['status'] ?? '');
        if ($status !== TakaPayStatusCodeEnum::SUCCESS->value) {
            throw new \App\Exceptions\ApiException($result['description'] ?? 'Deposit request failed');
        }

        // 記錄三方訂單號（如果有）
        if (isset($result['systemTransactionId'])) {
            $order->update(['third_party_order_no' => $result['systemTransactionId']]);
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT, // 跳轉 URL
            'url' => $result['redirectUrl'] ?? '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['systemTransactionId'] ?? '',
        ]);
    }
}
