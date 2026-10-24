<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VtPay;

use App\Exceptions\ApiException;

/**
 * VtPay AES 加解密服務
 *
 * 參照官方範例：PHP_example_encrypt_decrypt.php
 * 使用 AES-128-CBC 模式進行加解密
 */
class VtPayCryptoService
{
    private const string AES_METHOD = 'AES-128-CBC';

    /**
     * AES 加密
     *
     * @param  array  $data  要加密的數據
     * @param  string  $base64Key  Base64 編碼的密鑰
     * @return string Base64 編碼的加密結果
     *
     * @throws ApiException|\Random\RandomException
     */
    public function encrypt(array $data, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);

        if ($key === false || strlen($key) !== 16) {
            throw new ApiException('Invalid AES key');
        }

        // 生成隨機 IV
        $iv = random_bytes(16);

        // AES-128-CBC 加密
        $value = openssl_encrypt(
            json_encode($data, JSON_UNESCAPED_SLASHES),
            self::AES_METHOD,
            $key,
            0,
            $iv
        );

        if ($value === false) {
            throw new ApiException('Encryption failed');
        }

        // 構造 payload 並 Base64 編碼
        return base64_encode(json_encode([
            'iv' => base64_encode($iv),
            'value' => $value,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * AES 解密
     *
     * 支援兩種格式：
     * 1. 標準格式：{"iv": "...", "value": "..."}（加密的響應）
     * 2. 純 JSON：直接返回未加密的 JSON（某些 API 響應）
     *
     * @param  string  $encrypted  加密的數據或純 JSON
     * @param  string  $base64Key  Base64 編碼的密鑰
     * @return array 解密後的數據
     *
     * @throws ApiException
     */
    public function decrypt(string $encrypted, string $base64Key): array
    {
        // 嘗試 1：直接作為 JSON 解析（響應未加密）
        $result = json_decode($encrypted, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($result)) {
            return $result;
        }

        // 嘗試 2：解密
        $key = base64_decode($base64Key, true);

        if ($key === false || strlen($key) !== 16) {
            throw new ApiException('Invalid AES key');
        }

        // 檢查是否為有效的 base64
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $encrypted)) {
            throw new ApiException('Response is neither JSON nor base64 encoded data');
        }

        // Base64 解碼
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false) {
            throw new ApiException('Invalid base64 encoded data');
        }

        // JSON 解析
        $payload = json_decode($decoded, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = json_last_error_msg();
            throw new ApiException(sprintf('Invalid encrypted data format: %s', $errorMsg));
        }

        // 檢查是否包含錯誤代碼（直接在 payload 中）
        if (!empty($payload['error_code']) && (int) $payload['error_code'] !== 200) {
            $errorMessage = $payload['data']['message'] ?? $payload['message'] ?? 'Unknown error';
            throw new ApiException(sprintf('API error: %s', $errorMessage));
        }

        // 標準 CBC 格式：包含 iv 和 value
        if (isset($payload['iv'], $payload['value'])) {
            $iv = base64_decode($payload['iv'], true);

            if ($iv === false || strlen($iv) !== 16) {
                throw new ApiException('Invalid IV');
            }

            $decrypted = openssl_decrypt(
                $payload['value'],
                self::AES_METHOD,
                $key,
                0,
                $iv
            );

            if ($decrypted === false) {
                throw new ApiException('Decryption failed');
            }

            $result = json_decode($decrypted, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException('Invalid decrypted data format');
            }

            return $result;
        }

        // 如果 payload 本身就是有效的響應數據（已經解密但沒有 iv/value 結構）
        if (is_array($payload)) {
            return $payload;
        }

        throw new ApiException('Unable to decrypt data');
    }
}
