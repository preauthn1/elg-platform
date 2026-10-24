<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TopPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Support\Facades\Http;

trait TopPayTrait
{
    protected string $apiUrlField = 'API_URL';

    protected string $merchantCodeField = 'MERCHANT_CODE';

    protected string $privateKeyField = 'MERCHANT_PRIVATE_KEY';

    protected string $publicKeyField = 'PLATFORM_PUBLIC_KEY';

    protected function sendRequest(string $url, array $params): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $baseUrl = rtrim($this->info[$this->apiUrlField], '/');
        $fullUrl = $baseUrl . $url;

        $result = Http::logAction($action)
            ->asJson()
            ->post($fullUrl, $params)
            ->throw()
            ->json();

        return $result;
    }

    /**
     * 生成請求簽名
     *
     * 步驟：
     * 1. 排除 sign 本身及值為 null 或空字串的欄位
     * 2. 將剩餘欄位的 Key 按 ASCII 升序排序
     * 3. 串接所有 value（不含 Key、不加分隔符）
     * 4. 用商戶私鑰 RSA 加密，輸出 Base64
     */
    protected function generateSign(array $params): string
    {
        $stringToSign = $this->buildStringToSign($params);
        $privateKey = $this->info[$this->privateKeyField];

        return $this->rsaPrivateEncrypt($stringToSign, $privateKey);
    }

    /**
     * 驗證 TopPay 回調簽名（用平台公鑰解密）
     */
    protected function verifySign(array $params): bool
    {
        $sign = $params['sign'] ?? null;
        if (!is_string($sign) || $sign === '') {
            return false;
        }

        $stringToSign = $this->buildStringToSign($params);
        $publicKey = $this->info[$this->publicKeyField];

        $decrypted = $this->rsaPublicDecrypt($sign, $publicKey);

        return hash_equals($stringToSign, $decrypted);
    }

    /**
     * 驗證 callback 簽名
     *
     * ⚠️ TopPay 簽章端與傳送端格式不一致（屬對方 API 設計缺陷，非我方修飾資料）：
     *   - 簽章端 StrA：amount/fee 以 2 位小數參與簽章（例如 "10000.00"、"100.00"）
     *   - 傳送端 JSON：amount/fee 以整數送出（例如 10000、100）
     *
     * 已實測：用平台公鑰解 sign 還原的 StrA 為 "10000.00..."，但 callback body 為 10000。
     * 兩者差距正是 ".00"。
     *
     * 本方法為對齊「TopPay 簽章端」的不得已工作迴避——驗章前把 amount/fee 補上 .00。
     * 標準做法應為「用收到的原始資料驗章」，但 TopPay 不修正此行為前無解。
     *
     * 如未來 TopPay 修正成「簽章端跟傳送端格式一致」，本方法可降為直接呼叫 verifySign。
     */
    protected function verifyCallbackSign(array $params): bool
    {
        foreach (['amount', 'fee'] as $field) {
            if (isset($params[$field]) && is_numeric($params[$field])) {
                $params[$field] = Common::numAdd('0', (string) $params[$field], 2);
            }
        }

        return $this->verifySign($params);
    }

    /**
     * 排除 sign / null / 空字串，排序後串接所有 value
     */
    private function buildStringToSign(array $params): string
    {
        $filtered = array_filter(
            $params,
            fn ($value, $key) => $key !== 'sign' && $value !== null && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        ksort($filtered, SORT_STRING);

        return implode('', array_map(
            fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
            $filtered,
        ));
    }

    /**
     * RSA 私鑰加密（分塊 + Base64）
     */
    protected function rsaPrivateEncrypt(string $stringToSign, string $privateKey): string
    {
        $pem = $this->wrapPem($privateKey, 'PRIVATE KEY');

        $pk = openssl_pkey_get_private($pem);
        if ($pk === false) {
            throw new ApiException('Failed to load merchant private key');
        }

        $details = openssl_pkey_get_details($pk);
        $blockSize = (int) ($details['bits'] / 8) - 11;

        $encrypted = '';
        foreach (str_split($stringToSign, $blockSize) as $chunk) {
            $chunkEncrypted = '';
            if (!openssl_private_encrypt($chunk, $chunkEncrypted, $pk)) {
                throw new ApiException('RSA private encryption failed');
            }
            $encrypted .= $chunkEncrypted;
        }

        return base64_encode($encrypted);
    }

    /**
     * RSA 公鑰解密（分塊解密還原原文）
     */
    protected function rsaPublicDecrypt(string $data, string $publicKey): string
    {
        $pem = $this->wrapPem($publicKey, 'PUBLIC KEY');

        $pk = openssl_pkey_get_public($pem);
        if ($pk === false) {
            return '';
        }

        $raw = base64_decode($data, true);
        if ($raw === false) {
            return '';
        }

        $details = openssl_pkey_get_details($pk);
        $blockSize = (int) ($details['bits'] / 8);

        $decrypted = '';
        foreach (str_split($raw, $blockSize) as $chunk) {
            $chunkDecrypted = '';
            if (!openssl_public_decrypt($chunk, $chunkDecrypted, $pk)) {
                return '';
            }
            $decrypted .= $chunkDecrypted;
        }

        return $decrypted;
    }

    /**
     * 把純 Base64 金鑰包成標準 PEM 格式
     */
    private function wrapPem(string $rawKey, string $label): string
    {
        $rawKey = preg_replace('/\s+/', '', $rawKey);

        return "-----BEGIN {$label}-----\n"
            . wordwrap($rawKey, 64, "\n", true)
            . "\n-----END {$label}-----\n";
    }
}
