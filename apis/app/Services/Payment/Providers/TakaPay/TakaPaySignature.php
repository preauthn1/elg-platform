<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TakaPay;

class TakaPaySignature
{
    private string $signField = 'securityToken';

    /**
     * 生成簽名（用於 Deposit 和 Withdrawal 請求）
     *
     * @param  array  $fields  要簽名的字段值（按順序）
     * @param  string  $secret  密鑰
     * @return string MD5 簽名（大寫）
     */
    public function generateSecurityToken(array $fields, string $secret): string
    {
        $signString = implode('', $fields) . $secret;

        return md5($signString);
    }

    /**
     * 生成簽名（通用方法，用於回調驗證）
     */
    public function generateSign(array $params, string $secret): string
    {
        // 只移除 null 與空字串，保留 0 與 '0'
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // 排除簽名字段
        unset($filtered[$this->signField]);

        ksort($filtered);

        $sign = urldecode(http_build_query($filtered));
        $sign = $sign . '&key=' . $secret;

        return strtoupper(md5($sign));
    }

    /**
     * 驗證簽名（用於回調）
     */
    public function verifySecurityToken(array $params, array $info, string $merchantCode): bool
    {
        $token = [REDACTED] ?? '';
        $secret = [REDACTED]'SECRET_KEY'] ?? '';

        $signFields = [
            $merchantCode,
            $params['status'] ?? '',
            $params['currencyCode'] ?? '',
            $params['amount'] ?? '',
            $params['reference'] ?? '',
            $params['transactionTime'] ?? '',
            $params['systemTransactionId'] ?? '',
        ];

        $expected = $this->generateSecurityToken($signFields, $secret);

        return hash_equals(strtolower($expected), strtolower($token));
    }

    /**
     * 驗證簽名（用於回調，使用 credentials）
     */
    public function verifySecurityTokenWithCredentials(array $params, TakaPayCredentials $credentials): bool
    {
        $token = [REDACTED] ?? '';

        $signFields = [
            $credentials->merchantCode,
            $params['status'] ?? '',
            $params['currencyCode'] ?? '',
            $params['amount'] ?? '',
            $params['reference'] ?? '',
            $params['transactionTime'] ?? '',
            $params['systemTransactionId'] ?? '',
        ];

        $expected = $this->generateSecurityToken($signFields, $credentials->secretKey);

        return hash_equals(strtolower($expected), strtolower($token));
    }

    /**
     * 驗證簽名（通用方法）
     */
    public function checkSign(array $params, array $info): bool
    {
        $merchantCode = $info['MERCHANT_CODE'] ?? '';

        if (empty($merchantCode)) {
            return false;
        }

        return $this->verifySecurityToken($params, $info, $merchantCode);
    }

    /**
     * 驗證簽名（通用方法，使用 credentials）
     */
    public function checkSignWithCredentials(array $params, TakaPayCredentials $credentials): bool
    {
        return $this->verifySecurityTokenWithCredentials($params, $credentials);
    }
}
