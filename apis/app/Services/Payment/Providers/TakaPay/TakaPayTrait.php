<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TakaPay;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

trait TakaPayTrait
{
    protected string $apiUrlField = 'API_URL';

    protected ?TakaPaySignature $signature = null;

    /**
     * 回調成功回應（依 API 文件）
     */
    protected function successResponse(): array
    {
        return [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
        ];
    }

    /**
     * 回調失敗回應（依 API 文件）
     */
    protected function failResponse(): array
    {
        return [
            'status' => TakaPayStatusCodeEnum::FAILED_NOTIFY_CALLBACK->value,
        ];
    }

    /**
     * 發送請求
     */
    private function sendRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $url = rtrim($this->info[$this->apiUrlField], '/') . $url;

        $result = Http::logAction($action)
            ->asForm() // TakaPay 使用 Form 格式
            ->withHeaders($headers)
            ->post($url, $params)
            ->throw()
            ->json();

        return $result;
    }

    /**
     * 生成簽名（用於 Deposit 和 Withdrawal 請求）
     *
     * @param  array  $fields  要簽名的字段值（按順序）
     * @param  string  $secret  密鑰
     * @return string MD5 簽名（大寫）
     */
    protected function generateSecurityToken(array $fields, string $secret): string
    {
        return $this->signature()->generateSecurityToken($fields, $secret);
    }

    /**
     * 生成簽名（通用方法，用於回調驗證）
     */
    private function generateSign(array $params, string $secret): string
    {
        return $this->signature()->generateSign($params, $secret);
    }

    /**
     * 驗證簽名（用於回調）
     *
     * @param  array  $params  回調參數
     * @param  array  $info  配置信息
     * @param  string  $merchantCode  商戶代碼
     */
    private function verifySecurityToken(array $params, array $info, string $merchantCode): bool
    {
        return $this->signature()->verifySecurityToken($params, $info, $merchantCode);
    }

    /**
     * 驗證簽名（通用方法）
     */
    private function checkSign(array $params, array $info): bool
    {
        $credentials = $this->getMerchantCredentialsFromInfo($info);
        if ($credentials === null) {
            return false;
        }

        return $this->signature()->checkSignWithCredentials($params, $credentials);
    }

    /**
     * 從 payment code 解析 paymentMethod/paymentCode
     *
     * 格式範例: ewallet.bkash
     */
    protected function parsePaymentMethodAndCode(string $rawCode): array
    {
        $parts = explode('.', $rawCode, 2);

        if (!isset($parts[1])) {
            throw new ApiException(sprintf('%s: Invalid payment code format. Expected "method.code" format, got "%s"', $this->getDriverName(), $rawCode));
        }

        $paymentMethod = $parts[0];
        $paymentCode = $parts[1];

        return [
            'paymentMethod' => $paymentMethod,
            'paymentCode' => $paymentCode,
        ];
    }

    private function signature(): TakaPaySignature
    {
        if ($this->signature === null) {
            $this->signature = new TakaPaySignature;
        }

        return $this->signature;
    }

    protected function getMerchantCredentials(): TakaPayCredentials
    {
        $merchantCode = $this->info['MERCHANT_CODE'] ?? '';
        $secretKey = $this->info['SECRET_KEY'] ?? '';

        if ($merchantCode === '' || $secretKey === '') {
            throw new ApiException('TakaPay credentials are missing');
        }

        return new TakaPayCredentials($merchantCode, $secretKey);
    }

    protected function getMerchantCredentialsFromInfo(array $info): ?TakaPayCredentials
    {
        $merchantCode = $info['MERCHANT_CODE'] ?? '';
        $secretKey = $info['SECRET_KEY'] ?? '';

        if ($merchantCode === '' || $secretKey === '') {
            return null;
        }

        return new TakaPayCredentials($merchantCode, $secretKey);
    }
}
