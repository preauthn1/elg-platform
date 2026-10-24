<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VaderPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use VaderPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理 VaderPay 代收
     *
     * @param  DepositRequestData  $request  代收請求資料
     * @return DepositResponseData 代收響應資料
     *
     * @throws ApiException 當 API 請求失敗時
     */
    public function handle(DepositRequestData $request): DepositResponseData
    {
        $member = $request->member;
        $order = $request->order;
        $credentials = $this->getMerchantCredentials();
        $currencyCode = $this->agentCashFlow->currency->code;
        $amount = sprintf('%.2f', (float) $order->amount);
        $orderType = $order->orderType;
        $type = (int) ($orderType->type ?? 0); // 2:bank-transfer, 3:QR, 1:ewallet
        [$endpoint, $payload] = $this->buildVaderPayDepositPayload(
            type: $type,
            member: $member,
            order: $order,
            credentials: $credentials,
            currencyCode: $currencyCode,
            amount: $amount,
            orderType: $orderType,
            request: $request
        );

        $result = $this->sendJsonRequest($endpoint, $payload);

        // error_code 並非所有端點都會回傳（如 /Payin/DepositV2），僅在欄位存在且非 0 時才視為失敗
        $errorCode = $result['error_code'] ?? null;
        if ($errorCode !== null && (int) $errorCode !== 0) {
            $errorMessage = $result['error_message'] ?? 'Deposit request failed';
            Log::error('VaderPay 代收請求失敗', ['error_code' => $errorCode, 'error_message' => $errorMessage]);
            throw new ApiException(sprintf('%s deposit error: %s', $this->getDriverName(), $errorMessage));
        }

        // 回應中必須含有 redirect_to（付款跳轉網址），否則視為請求失敗
        if (!isset($result['redirect_to'])) {
            $errorMessage = $result['error_message'] ?? 'Deposit request failed';
            Log::error('VaderPay 代收回應缺少 redirect_to', ['error_message' => $errorMessage, 'result' => $result]);
            throw new ApiException(sprintf('%s deposit error: %s', $this->getDriverName(), $errorMessage));
        }

        $providerOrderNo = (string) ($result['transaction'] ?? '');
        if ($providerOrderNo !== '') {
            $order->update(['third_party_order_no' => $providerOrderNo]);
        }

        return DepositResponseData::from([
            'type' => 1,
            'url' => $result['redirect_to'],
            'orderNo' => $order->order_no,
            'providerOrderNo' => $providerOrderNo,
        ]);
    }

    private function buildVaderPayDepositPayload(
        int $type,
        $member,
        $order,
        $credentials,
        string $currencyCode,
        string $amount,
        $orderType,
        $request
    ): array {
        return match ($type) {
            2 => $this->buildBankTransferPayload($member, $order, $credentials, $currencyCode, $amount, $orderType, $request),
            3 => $this->buildQrPayload($member, $order, $credentials, $currencyCode, $amount, $request),
            1 => $this->buildEwalletPayload($member, $order, $credentials, $currencyCode, $amount, $orderType, $request),
            default => throw new ApiException('Unsupported VaderPay payment type'),
        };
    }

    private function buildBankTransferPayload($member, $order, $credentials, string $currencyCode, string $amount, $orderType, $request): array
    {
        $bankCode = $orderType->key ?? '';
        if ($bankCode === '') {
            throw new ApiException(sprintf('%s deposit bank code is missing', $this->getDriverName()));
        }
        $clientFullName = trim(sprintf('%s %s', (string) $member->first_name, (string) $member->last_name));
        if ($clientFullName === '') {
            $clientFullName = $member->username;
        }

        return ['/Payin/DepositV2', [
            'MerchantCode' => $credentials->merchantCode,
            'ReturnURL' => $request->returnUrl,
            'Amount' => $amount,
            'Currency' => $currencyCode,
            'ItemID' => $order->order_no,
            'ItemDescription' => $order->order_no,
            'PlayerId' => $member->username,
            'Hash' => $this->generateDepositHash(
                $credentials->merchantCode,
                $order->order_no,
                $currencyCode,
                $amount,
                $credentials->apiKey
            ),
            'BankCode' => $bankCode,
            'Email' => $member->email ?? '',
            'Phone' => $member->mobile ?? '',
            'ClientFullName' => $clientFullName,
        ]];
    }

    private function buildQrPayload($member, $order, $credentials, string $currencyCode, string $amount, $request): array
    {
        $this->assertWholeAmount($amount, 'QR');

        return ['/BankBot/QRScanDeposit/', [
            'merchant_code' => $credentials->merchantCode,
            'ref_id' => $order->order_no,
            'player_username' => $member->username,
            'player_ip' => $request->ip,
            'currency_code' => $currencyCode,
            'amount' => $amount,
            'client_url' => $request->returnUrl,
            'hash' => $this->generatePayinQrEwalletHash(
                $credentials->merchantCode,
                $order->order_no,
                $member->username,
                $request->ip,
                $currencyCode,
                $amount,
                $request->returnUrl,
                $credentials->apiKey
            ),
            'email' => $member->email ?? '',
            'phone_number' => $member->mobile ?? '',
            'cpf_no' => $member->cpf_no ?? '',
        ]];
    }

    private function buildEwalletPayload($member, $order, $credentials, string $currencyCode, string $amount, $orderType, $request): array
    {
        $this->assertWholeAmount($amount, 'ewallet');

        return ['/Payin/Ewallet', [
            'merchant_code' => $credentials->merchantCode,
            'ref_id' => $order->order_no,
            'player_username' => $member->username,
            'player_ip' => $request->ip,
            'currency_code' => $currencyCode,
            'amount' => $amount,
            'account_no' => $member->ewallet_account_no ?? '',
            'ewallet_type' => $orderType->key ?? '',
            'client_url' => $request->returnUrl,
            'hash' => $this->generatePayinQrEwalletHash(
                $credentials->merchantCode,
                $order->order_no,
                $member->username,
                $request->ip,
                $currencyCode,
                $amount,
                $request->returnUrl,
                $credentials->apiKey
            ),
            'email' => $member->email ?? '',
            'phone_number' => $member->mobile ?? '',
            'client_name' => $member->username,
        ]];
    }

    private function assertWholeAmount(string $amount, string $context): void
    {
        if (Common::numCompare($amount, (string) (int) $amount, 2) !== 0) {
            throw new ApiException(sprintf(
                '%s %s amount must be a whole number',
                $this->getDriverName(),
                $context
            ));
        }
    }
}
