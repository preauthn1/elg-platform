<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VaderPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Support\Facades\Http;

trait VaderPayTrait
{
    protected string $apiUrlField = 'API_URL';

    protected string $merchantCodeField = 'MERCHANT_CODE';

    protected string $apiKeyField = 'API_KEY';

    private ?VaderPaySignature $signature = null;

    /**
     * 回調成功回應
     */
    protected function successResponse(): array
    {
        return [
            'error_code' => 0,
            'message' => 'Operation Success',
        ];
    }

    /**
     * 回調失敗回應
     */
    protected function failResponse(): array
    {
        return [
            'error_code' => -1,
            'message' => 'Operation Failed',
        ];
    }

    /**
     * 發送 JSON 請求
     */
    protected function sendJsonRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendJsonRequest';
        $url = rtrim($this->info[$this->apiUrlField], '/') . $url;

        $response = Http::logAction($action)
            ->asJson()
            ->withHeaders($headers)
            ->post($url, $params);

        return $response->throw()->json();

    }

    protected function getMerchantCredentials(): VaderPayCredentials
    {
        $merchantCode = $this->info[$this->merchantCodeField] ?? '';
        $apiKey = [REDACTED] ?? '';

        if ($merchantCode === '' || $apiKey === '') {
            throw new ApiException(sprintf('%s credentials are missing', $this->getDriverName()));
        }

        return new VaderPayCredentials($merchantCode, $apiKey);
    }

    protected function getMerchantCredentialsFromInfo(array $info): ?VaderPayCredentials
    {
        $merchantCode = $info[$this->merchantCodeField] ?? '';
        $apiKey = [REDACTED] ?? '';

        if ($merchantCode === '' || $apiKey === '') {
            return null;
        }

        return new VaderPayCredentials($merchantCode, $apiKey);
    }

    protected function generateDepositHash(
        string $merchantCode,
        string $itemId,
        string $currency,
        string $amount,
        string $apiKey
    ): string {
        return $this->signature()->generateDepositHash($merchantCode, $itemId, $currency, $amount, $apiKey);
    }

    protected function generatePayoutHash(
        string $merchantCode,
        string $refId,
        string $playerUsername,
        string $playerIp,
        string $currencyCode,
        string $amount,
        string $bankCode,
        string $beneficiaryAccount,
        string $beneficiaryName,
        string $apiKey
    ): string {
        return $this->signature()->generatePayoutHash(
            $merchantCode,
            $refId,
            $playerUsername,
            $playerIp,
            $currencyCode,
            $amount,
            $bankCode,
            $beneficiaryAccount,
            $beneficiaryName,
            $apiKey
        );
    }

    protected function generatePayinCallbackSignature(
        string $invoiceNo,
        string $receiverBank,
        string $receiverAccount,
        string $currency,
        string $amount,
        string $paymentDate,
        string $apiKey
    ): string {
        return $this->signature()->generatePayinCallbackSignature(
            $invoiceNo,
            $receiverBank,
            $receiverAccount,
            $currency,
            $amount,
            $paymentDate,
            $apiKey
        );
    }

    protected function generatePayinQrEwalletHash(
        string $merchantCode,
        string $refId,
        string $playerUsername,
        string $playerIp,
        string $currencyCode,
        string $amount,
        string $clientUrl,
        string $apiKey
    ): string {
        return $this->signature()->generatePayinQrEwalletHash(
            $merchantCode,
            $refId,
            $playerUsername,
            $playerIp,
            $currencyCode,
            $amount,
            $clientUrl,
            $apiKey
        );
    }

    protected function generatePayoutCallbackSignature(
        string $invoiceNo,
        string $status,
        string $currency,
        string $amount,
        string $submissionDate,
        string $apiKey
    ): string {
        return $this->signature()->generatePayoutCallbackSignature(
            $invoiceNo,
            $status,
            $currency,
            $amount,
            $submissionDate,
            $apiKey
        );
    }

    protected function verifyPayinCallbackSignature(array $input, VaderPayCredentials $credentials): bool
    {
        $amount = $this->normalizeSignatureAmount($input['Amount'] ?? null);

        $expected = $this->generatePayinCallbackSignature(
            (string) ($input['InvoiceNo'] ?? ''),
            (string) ($input['ReceiverBank'] ?? ''),
            (string) ($input['ReceiverAccount'] ?? ''),
            (string) ($input['Currency'] ?? ''),
            $amount,
            (string) ($input['PaymentDate'] ?? ''),
            $credentials->apiKey
        );

        return hash_equals(strtolower($expected), strtolower((string) ($input['Signature'] ?? '')));
    }

    protected function verifyPayoutCallbackSignature(array $input, VaderPayCredentials $credentials): bool
    {
        $amount = $this->normalizeSignatureAmount($input['Amount'] ?? null);

        $expected = $this->generatePayoutCallbackSignature(
            (string) ($input['InvoiceNo'] ?? ''),
            (string) ($input['Status'] ?? ''),
            (string) ($input['Currency'] ?? ''),
            $amount,
            (string) ($input['PaymentDate'] ?? ''),
            $credentials->apiKey
        );

        return hash_equals(strtolower($expected), strtolower((string) ($input['Signature'] ?? '')));
    }

    private function signature(): VaderPaySignature
    {
        if ($this->signature === null) {
            $this->signature = new VaderPaySignature;
        }

        return $this->signature;
    }

    /**
     * 不要在此加 (string) 轉型：Common::numAdd 內部的 toString() 對 float 走
     * number_format($n, 10)，而 PHP 預設轉型會產生科學記號（(string) 0.00001
     * 得到 '1.0E-5'），bc 收到會拋 ValueError。原值直接傳入才走得到正規化。
     */
    private function normalizeSignatureAmount(string|int|float|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return Common::numAdd($amount, '0', 2);
    }
}
