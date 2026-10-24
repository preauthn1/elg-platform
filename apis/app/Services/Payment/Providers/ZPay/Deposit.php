<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\ZPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use ZPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $member = $request->member;
        $order = $request->order;
        $credentials = $this->getMerchantCredentials();

        // 取得銀行代碼並映射到 ZPay BankCode
        $bankCode = $order->orderType->key ?? '';

        $amount = (string) $order->amount;
        $itemDescSign = $this->generateItemDescSign(
            $order->order_no,
            $amount,
            $credentials->secretKey
        );

        // 將簽名後的 itemDesc 存儲到訂單的 extra_content 中，以便後續回調驗證使用
        $extraContent = Common::safeJsonDecode($order->extra_content);
        $extraContent['zpay_item_desc'] = $itemDescSign;
        $order->update([
            'extra_content' => Common::safeJsonEncode($extraContent),
        ]);

        // ZPay HTTP GET return requires URL to contain ?
        $linkBackUrl = $request->returnUrl;
        if (!str_contains($linkBackUrl, '?')) {
            $pos = strpos($linkBackUrl, '#');
            $linkBackUrl = $pos !== false ? substr($linkBackUrl, 0, $pos) . '?' . substr($linkBackUrl, $pos) : $linkBackUrl . '?';
        }

        // 構建請求參數（ZPay 實際 API 需要 JSON 格式）
        $requestParams = [
            'Token' => $credentials->token,
            'ApiUserCode' => $member->username,
            'BankCode' => $bankCode,
            'CallBackUrl' => $request->callbackUrl,
            'LinkBackUrl' => $linkBackUrl,
            'ReferenceId' => $order->order_no,
            'Amount' => (float) $amount,
            // 'AllowQR' => true, // 是否顯示QR付款選項，預設關
            // 'AllowFPX' => false, // 是否啟用FPX付款，預設開
            // 'ForceRealName' => true,
            // 'Demo' => 'on',
            'ItemDesc' => $itemDescSign,
        ];

        if ($bankCode === '') {
            unset($requestParams['BankCode']);
        }

        try {
            $result = $this->sendJsonRequest('/ApiRobot/ApiAllocateServer.aspx', $requestParams);
        } catch (\Throwable $e) {
            Log::error(sprintf('%s deposit request failed', $this->getDriverName()), [
                'order_no' => $order->order_no,
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }

        // ZPay API 返回格式：
        // {"Code":1, "Message":"Success", "Data":{"Status":1, "Domain":"base64_encoded_url", "TransactionId":0}}
        if (!isset($result['Code']) || $result['Code'] !== 1) {
            $errorMessage = $result['Message'] ?? 'Unknown error';

            Log::error(sprintf('%s deposit failed', $this->getDriverName()), [
                'order_no' => $order->order_no,
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'response' => $result,
            ]);

            throw new ApiException(
                sprintf('%s deposit error: %s', $this->getDriverName(), $errorMessage)
            );
        }

        // 解析返回數據
        $data = $result['Data'] ?? [];
        $domain = $data['Domain'] ?? '';
        $transactionId = $data['TransactionId'] ?? 0;

        // Domain is Base64 encoded
        if ($domain !== '') {
            $decodedDomain = base64_decode($domain, true);
            if ($decodedDomain === false) {
                throw new ApiException(
                    sprintf('%s deposit error: invalid domain encoding', $this->getDriverName())
                );
            }

            $domain = $decodedDomain;
            if (filter_var($domain, FILTER_VALIDATE_URL) === false) {
                throw new ApiException(
                    sprintf('%s deposit error: invalid domain url', $this->getDriverName())
                );
            }
        }

        // 記錄三方訂單號
        if ($transactionId > 0) {
            $order->update(['third_party_order_no' => (string) $transactionId]);
        }

        Log::info(sprintf('%s deposit success', $this->getDriverName()), [
            'order_no' => $order->order_no,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'transaction_id' => $transactionId,
            'domain' => $domain,
        ]);

        return DepositResponseData::from([
            'type' => 1, // URL redirect
            'url' => $domain ?: '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => (string) $transactionId,
        ]);
    }
}
