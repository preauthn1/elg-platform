<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayEssence;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait PayEssenceTrait
{
    /**
     * 發送請求
     */
    protected function sendRequest(string $endpoint, array $params): array
    {
        try {
            $url = $this->getApiUrl() . $endpoint;

            Log::debug('PayEssence API Request', [
                'url' => $url,
                'params' => $this->maskSensitiveData($params),
            ]);

            $response = Http::timeout(30)
                ->post($url, $params);

            $result = $response->json();

            Log::debug('PayEssence API Response', [
                'url' => $url,
                'status' => $response->status(),
                'response' => $this->maskSensitiveData($result),
            ]);

            if (!$response->successful()) {
                throw new ApiException(
                    sprintf('PayEssence API Error: HTTP %d', $response->status())
                );
            }

            return $result ?? [];
        } catch (\Exception $e) {
            Log::error('PayEssence API Exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 計算簽名 (SHA256)
     * Signature = SHA256(MerchantCode + SecretKey + TransNum + Currency + Amount)
     */
    protected function generateDepositSignature(
        string $merchantCode,
        string $secretKey,
        string $transNum,
        string $currency,
        string $amount
    ): string {
        $concatString = $merchantCode . $secretKey . $transNum . $currency . $amount;

        return hash('sha256', $concatString);
    }

    /**
     * 計算提款簽名 (SHA256)
     * Signature = SHA256(MerchantCode + SecretKey + MerchantTransNum + ToBankId + ToBankAccountNum + CurrencyTypeId + Amount)
     */
    protected function generateWithdrawalSignature(
        string $merchantCode,
        string $secretKey,
        string $transNum,
        string $toBankId,
        string $toBankAccountNum,
        string $currencyTypeId,
        string $amount
    ): string {
        $concatString = $merchantCode . $secretKey . $transNum . $toBankId . $toBankAccountNum . $currencyTypeId . $amount;

        return hash('sha256', $concatString);
    }

    /**
     * 驗證回調的 CheckString
     * CheckString = SHA256(MerchantCode + SecretKey + TransNum)
     */
    protected function verifyDepositCheckString(
        string $merchantCode,
        string $secretKey,
        string $transNum,
        string $checkString
    ): bool {
        $expectedCheckString = hash('sha256', $merchantCode . $secretKey . $transNum);

        return hash_equals($expectedCheckString, $checkString);
    }

    /**
     * 驗證回調的 CheckString2
     * CheckString2 = SHA256(Reversed(MerchantCode) + Reversed(TransNum) + SecretKey)
     */
    protected function verifyDepositCheckString2(
        string $merchantCode,
        string $secretKey,
        string $transNum,
        string $checkString2
    ): bool {
        $reversedMerchantCode = strrev($merchantCode);
        $reversedTransNum = strrev($transNum);
        $concatReversed = $reversedMerchantCode . $reversedTransNum;
        $expectedCheckString2 = hash('sha256', $concatReversed . $secretKey);

        return hash_equals($expectedCheckString2, $checkString2);
    }

    /**
     * 驗證代付回調的 CheckString2
     * CheckString2 = SHA256(MerchantCode + SecretKey + TransNum + PayoutId + ToBankId + ToBankAccountNum + CurrencyTypeId + Amount)
     */
    protected function verifyWithdrawalCheckString2(
        string $merchantCode,
        string $secretKey,
        string $transNum,
        string $payoutId,
        string $toBankId,
        string $toBankAccountNum,
        string $currencyTypeId,
        string $amount,
        string $checkString2
    ): bool {
        $concatString = $merchantCode . $secretKey . $transNum . $payoutId . $toBankId . $toBankAccountNum . $currencyTypeId . $amount;
        $expectedCheckString2 = hash('sha256', $concatString);

        return hash_equals($expectedCheckString2, $checkString2);
    }

    /**
     * 獲取 API Base URL
     */
    protected function getApiUrl(): string
    {
        return $this->info['API_URL'];
    }

    /**
     * 隱遮敏感信息用於日誌
     */
    private function maskSensitiveData(array $data): array
    {
        $sensitive = ['SecretKey', 'secret_key', 'signature', 'Signature', 'pin', 'Pin'];
        $result = $data;

        foreach ($sensitive as $key) {
            if (isset($result[$key])) {
                $result[$key] = '******';
            }
        }

        return $result;
    }
}
