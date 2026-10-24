<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GlobePay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait GlobePayTrait
{
    // 欄位名稱常數
    private const string FIELD_API_URL = 'API_URL';

    private const string FIELD_MERCHANT_CODE = 'MERCHANT_CODE';

    private const string FIELD_AES_KEY = 'AES_KEY';

    private const string FIELD_RSA_PRIVATE_KEY = 'RSA_PRIVATE_KEY';

    private const string FIELD_RSA_PUBLIC_KEY = 'RSA_PUBLIC_KEY';

    private const string FIELD_CURRENCY = 'CURRENCY';

    // 定義返回三方的信息
    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    /**
     * 發送請求
     */
    protected function sendRequest(string $endpoint, array $data): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $url = rtrim($this->info[self::FIELD_API_URL], '/') . $endpoint;

        Log::info(sprintf('%s 準備請求', $this->getDriverName()), [
            'url' => $url,
            'data' => Common::maskData($data),
        ]);

        // 加密 Data 參數
        $encryptedData = $this->aesEncrypt(json_encode($data), $this->info[self::FIELD_AES_KEY]);

        // 生成 RSA 簽名
        $signature = $this->generateRsaSignature(json_encode($data), $this->info[self::FIELD_RSA_PRIVATE_KEY]);

        $params = [
            'merchantCode' => $this->info[self::FIELD_MERCHANT_CODE],
            'data' => $encryptedData,
            'signature' => $signature,
            'version' => 0,
        ];

        $result = Http::logAction($action)
            ->asJson()
            ->post($url, $params)
            ->json();

        return $result;
    }

    /**
     * AES 加密
     * 使用 AES-256-CBC 模式
     */
    protected function aesEncrypt(string $plainText, string $aesKey): string
    {
        // 使用 PBKDF2 生成 256 位密鑰
        $key = hash_pbkdf2('sha1', $aesKey, $aesKey, 1000, 32, true);

        // 生成隨機 IV
        $iv = openssl_random_pseudo_bytes(16);

        // AES-256-CBC 加密
        $encrypted = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        // IV + 密文
        $combined = $iv . $encrypted;

        // Base64 編碼
        return base64_encode($combined);
    }

    /**
     * AES 解密
     * 使用 AES-256-CBC 模式
     */
    protected function aesDecrypt(string $encryptedStringBase64, string $aesKey): string
    {
        // Base64 解碼
        $encryptedStringBase64 = base64_decode($encryptedStringBase64);

        // 使用 PBKDF2 生成 256 位密鑰
        $key = hash_pbkdf2('sha1', $aesKey, $aesKey, 1000, 32, true);

        // 提取 IV（前 16 bytes）
        $iv = substr($encryptedStringBase64, 0, 16);

        // 提取密文
        $encrypted = substr($encryptedStringBase64, 16);

        // AES-256-CBC 解密
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new ApiException('AES decryption failed');
        }

        return $decrypted;
    }

    /**
     * 生成 RSA SHA-1 簽名
     */
    protected function generateRsaSignature(string $plainText, string $privateKey): string
    {
        // 格式化私鑰為 PEM 格式（每 64 字符換行）
        $privateKey = chunk_split($privateKey, 64);

        // 添加 PEM 格式頭尾
        $privateKey = "[PRIVATE_KEY_BLOCK_REDACTED]';

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new ApiException('Invalid private key');
        }

        $signature = '';
        $success = openssl_sign($plainText, $signature, $key);

        if (!$success) {
            throw new ApiException('RSA signature generation failed');
        }

        return base64_encode($signature);
    }

    /**
     * 驗證 RSA SHA-1 簽名
     */
    protected function verifyRsaSignature(string $plainText, string $signature, string $publicKey): bool
    {
        // 格式化公鑰為 PEM 格式（每 64 字符換行）
        $publicKey = chunk_split($publicKey, 64);

        // 添加 PEM 格式頭尾
        $publicKey = "-----BEGIN PUBLIC KEY-----\n" . $publicKey . '-----END PUBLIC KEY-----';

        $publicKey = openssl_pkey_get_public($publicKey);

        if ($publicKey === false) {
            throw new ApiException('Invalid public key');
        }

        $signature = base64_decode($signature);

        $result = openssl_verify($plainText, $signature, $publicKey);

        return $result === 1;
    }
}
