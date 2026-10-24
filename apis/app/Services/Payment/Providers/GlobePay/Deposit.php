<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GlobePay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use GlobePayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理代收請求
     */
    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        // 構建請求參數
        $params = $this->buildDepositParams($request);

        // 發送請求
        $result = $this->sendRequest('/api/Deposit/SubmitDeposit', $params);

        // 驗證返回結果
        if (!isset($result['isSuccess']) || $result['isSuccess'] !== true) {
            $errorMessage = $result['errorMessage'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        // 檢查是否有資料
        if (empty($result['data'])) {
            throw new ApiException($this->getDriverName() . ' deposit error: missing payment data');
        }

        $data = $result['data'];

        // 記錄三方訂單號
        if (isset($data['transactionId'])) {
            $order->update(['third_party_order_no' => $data['transactionId']]);
        }

        // 根據返回的資料決定類型
        $type = DepositResponseType::URL_REDIRECT; // 預設 URL 跳轉
        $url = $data['url'] ?? '';

        // 如果有 QR Code，使用類型 2
        if (!empty($data['qrCode'])) {
            $type = DepositResponseType::QR_CODE_LEGACY;
            $url = $data['qrCode'];
        }

        return DepositResponseData::from([
            'type' => $type,
            'url' => $url,
            'orderNo' => $order->order_no,
            'providerOrderNo' => $data['transactionId'] ?? '',
        ]);
    }

    /**
     * 構建代收請求參數
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $order = $request->order;
        $member = $request->member;

        // 判斷是否為子層 OrderType（有 parent），透過關聯取得父層 OrderType
        $parentOrderType = $order->orderType->parentOrderType;
        $orderTypeKey = $parentOrderType ? $parentOrderType->key : $order->orderType->key;

        $params = [
            'MerchantCode' => $this->info[self::FIELD_MERCHANT_CODE],
            'MerchantTransactionId' => $order->order_no,
            'MerchantClientId' => $member->username,
            'CurrencyCode' => $this->info[self::FIELD_CURRENCY],
            'Amount' => sprintf('%.2f', $order->amount),
            'NotifyUrl' => $request->callbackUrl,
            'ReturnUrl' => $request->returnUrl,
            'IPAddress' => $request->ip,
            'PaymentMethodCode' => $orderTypeKey, // 支付方式代碼：OB=>Online banking (网银支付), FPX, DN=>DuitNow
            // 'AccountHolderName' => $member->first_name ?? '',
            // 'AccountNumber' => '',
        ];

        // 网银支付必填：銀行代碼
        if ($parentOrderType) {
            $params['SourceClientBankCode'] = $order->orderType->key;
        }

        Log::debug('GlobePay Deposit Params: ', ['params' => $params]);

        return $params;
    }
}
