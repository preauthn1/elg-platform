<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayEssence;

use App\Enums\DepositResponseType;
use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Models\OrderType;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use PayEssenceTrait;

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

        $apiUrl = $this->info['API_URL'] ?? null;
        $merchantCode = $this->info['MERCHANT_CODE'] ?? null;
        $secretKey = $this->info['SECRET_KEY'] ?? null;
        $country = $this->info['COUNTRY'] ?? '';

        if (!$merchantCode || !$secretKey || !$apiUrl) {
            throw new ApiException('PayEssence merchant code, secret key or API URL not configured');
        }

        $orderType = $order->orderType;
        $currency = $this->getCurrency($orderType) ?? '';

        $depositParams = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $order->order_no,
            'Currency' => $currency,
            'Amount' => (string) $order->amount,
            'FirstName' => $member->username,
            'Country' => $country,
        ];

        $transProvider = $this->getTransProvider($orderType);
        if ($transProvider !== null) {
            $depositParams['TransProvider'] = $transProvider;
        }

        $signature = $this->generateDepositSignature(
            $merchantCode,
            $secretKey,
            $order->order_no,
            $currency,
            (string) $order->amount
        );
        $depositParams['Signature'] = $signature;
        $depositParams['RedirectUrl'] = $params->returnUrl;

        // 記錄請求
        $traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);

        Log::info('PayEssence Deposit Request', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'amount' => $order->amount,
            'currency' => $currency,
            'trace_id' => $traceId,
        ]);

        // 更新訂單記錄
        $order->update([
            'third_party_order_no' => '',
            'remark' => json_encode([
                'trace_id' => $traceId,
                'request' => $this->maskSensitive($depositParams),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $depositFormUrl = rtrim($apiUrl, '/') . '/WebForm/Deposit.aspx';
        $html = $this->buildAutoSubmitForm($depositFormUrl, $depositParams);

        return DepositResponseData::from([
            'type' => DepositResponseType::HTML_FORM,
            'html' => $html,
            'orderNo' => $order->order_no,
        ]);
    }

    private function buildAutoSubmitForm(string $url, array $params): string
    {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Redirecting...</title></head><body>';
        $html .= '<form id="autoForm" method="POST" action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:none;">';

        foreach ($params as $key => $val) {
            $html .= '<input name="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . '">';
        }

        $html .= '</form>';
        $html .= '<script>document.getElementById("autoForm").submit();</script>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * 隱遮敏感信息
     */
    private function maskSensitive(array $data): array
    {
        $data['Signature'] = '******';

        return $data;
    }

    /**
     * 取得對應的 Currency 代碼
     */
    private function getCurrency(OrderType $orderType): ?string
    {
        return match (true) {
            $orderType->key === 'PAYESSENCE.EUSDT' => 'EUSDT',
            $orderType->key === 'PAYESSENCE.TUSDT' => 'TUSDT',
            default => $orderType->currency ? $orderType->currency->code : null,
        };
    }

    /**
     * 取得對應的 TransProvider 代碼
     */
    private function getTransProvider(OrderType $orderType): ?string
    {
        $map = [
            'PAYESSENCE.EUSDT' => '801',
            'PAYESSENCE.TUSDT' => '802',
        ];

        return $map[$orderType->key] ?? null;
    }
}
