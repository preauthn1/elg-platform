<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Kira;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait KiraTrait
{
    /**
     * 取得 API Base URL
     */
    protected function getApiUrl(): string
    {
        return rtrim($this->info['API_URL'] ?? '', '/');
    }

    /**
     * Token 快取 key 前綴依 driver 目錄名導出（kira_token_* / antzpay_token_*），
     * 確保同源協定的 provider（如 AntzPay 薄繼承）共用憑證時快取不互撞
     */
    protected function getTokenCacheKey(): string
    {
        $clientId = $this->info['CLIENT_ID'] ?? '';
        $clientSecret = [REDACTED]'CLIENT_SECRET'] ?? '';

        return sprintf('%s_token_%s', strtolower($this->getDriverName()), md5($clientId . $clientSecret));
    }

    /**
     * 取得 Access Token
     */
    protected function getAccessToken(): string
    {
        $clientId = $this->info['CLIENT_ID'] ?? '';
        $clientSecret = [REDACTED]'CLIENT_SECRET'] ?? '';
        $serverPublicKey = $this->info['SERVER_PUBLIC_KEY'] ?? '';

        if (empty($clientId) || empty($clientSecret) || empty($serverPublicKey)) {
            throw new ApiException(sprintf('%s CLIENT_ID, CLIENT_SECRET or SERVER_PUBLIC_KEY not configured', $this->getDriverName()));
        }

        // safeRemember 防快取擊穿：TTL 到期瞬間多請求併發 miss 會各自打 createToken，
        // 若三方採單一有效 token，舊 token 被作廢的請求會 401 誤停 21
        return Cache::safeRemember($this->getTokenCacheKey(), 82800, function () use ($clientId, $clientSecret, $serverPublicKey) {
            return $this->fetchAccessToken($clientId, $clientSecret, $serverPublicKey);
        });
    }

    /**
     * 從 Kira API 獲取新的 Access Token
     */
    protected function fetchAccessToken(string $clientId, string $clientSecret, string $serverPublicKey): string
    {
        // Step 1: Base64 encode "CLIENT_ID:CLIENT_SECRET"
        $plainCredentials = sprintf('%s:%s', $clientId, $clientSecret);
        $credentials = base64_encode($plainCredentials);

        // Step 2: RSA 加密 credentials 使用 SERVER_PUBLIC_KEY
        try {
            $encryptedData = $this->rsaEncrypt($credentials, $serverPublicKey);
        } catch (\Exception $e) {
            Log::error($this->getDriverName() . ' createToken RSA encrypt failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Step 3: 發送請求取得 Token
        $url = $this->getApiUrl() . '/gateway/v1/createToken';

        $response = Http::logAction($this->getDriverName() . ' ' . __FUNCTION__)
            ->timeout(30)
            ->asJson()
            ->post($url, ['data' => $encryptedData]);

        if (!$response->successful()) {
            throw new ApiException(
                sprintf('%s createToken error: HTTP %d', $this->getDriverName(), $response->status())
            );
        }

        $result = $response->json();

        if (!isset($result['code']) || $result['code'] != 200) {
            throw new ApiException(
                sprintf('%s createToken error: %s', $this->getDriverName(), $result['message'] ?? 'Unknown error')
            );
        }

        // Step 4: 用 CLIENT_SYMMETRIC_KEY 解密 encryptedToken 取得 Token
        $clientSymmetricKey = $this->info['CLIENT_SYMMETRIC_KEY'] ?? '';
        $encryptedToken = [REDACTED]'encryptedToken'] ?? '';

        if (empty($encryptedToken)) {
            throw new ApiException(sprintf('%s createToken error: encryptedToken is empty', $this->getDriverName()));
        }

        try {
            $tokenData = $this->aesDecrypt($encryptedToken, $clientSymmetricKey);
        } catch (\Exception $e) {
            Log::error($this->getDriverName() . ' createToken decryption failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $tokenData;
    }

    /**
     * 清除已快取的 Access Token
     */
    protected function clearAccessToken(): void
    {
        Cache::forget($this->getTokenCacheKey());
    }

    /**
     * 生成簽名
     *
     * 簽名流程：
     * 1. 將 JSON key 按字母順序排序（包含巢狀物件）
     * 2. 壓縮成緊湊格式
     * 3. Base64 編碼
     * 4. 組合 plain text: data={base64}&method=post&nonceStr={nonce}&requestUrl={path}&signType=sha256&timestamp={ts}
     * 5. 使用 CLIENT_PRIVATE_KEY 進行 RSA-SHA256 簽名
     */
    protected function generateSignature(array $data, string $requestPath, string $nonceStr, string $timestamp): string
    {
        // 排序 JSON key（遞迴）
        $sortedData = $this->sortArrayKeysRecursive($data);

        // 緊湊格式 JSON
        $jsonData = Common::safeJsonEncode($sortedData);

        // Base64 編碼
        $base64Data = base64_encode($jsonData);

        // 組合 plain text
        $plainText = sprintf(
            'data=%s&method=post&nonceStr=%s&requestUrl=%s&signType=sha256&timestamp=%s',
            $base64Data,
            $nonceStr,
            $requestPath,
            $timestamp
        );

        // RSA-SHA256 簽名
        $privateKey = $this->info['CLIENT_PRIVATE_KEY'] ?? '';
        if (empty($privateKey)) {
            throw new ApiException(sprintf('%s CLIENT_PRIVATE_KEY not configured', $this->getDriverName()));
        }

        $signature = $this->rsaSign($plainText, $privateKey);

        return 'sha256 ' . $signature;
    }

    /**
     * AES 加密 (用於加密請求體)
     */
    protected function aesEncrypt(string $data, string $key): string
    {
        $iv = md5($key, true);

        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new ApiException(sprintf('%s AES encryption failed', $this->getDriverName()));
        }

        return bin2hex($encrypted);
    }

    /**
     * AES 解密 (用於解密回應體)
     */
    protected function aesDecrypt(string $encryptedHex, string $key): string
    {
        $iv = md5($key, true);

        $encryptedData = hex2bin($encryptedHex);

        if ($encryptedData === false) {
            throw new ApiException(sprintf('%s AES decryption failed: invalid hex data', $this->getDriverName()));
        }

        $decrypted = openssl_decrypt($encryptedData, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new ApiException(sprintf('%s AES decryption failed', $this->getDriverName()));
        }

        return $decrypted;
    }

    /**
     * RSA 加密 (用於建立 Token)
     */
    protected function rsaEncrypt(string $data, string $publicKey): string
    {
        // 確保公鑰格式正確
        $publicKey = $this->formatPublicKey($publicKey);

        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            throw new ApiException(sprintf('%s RSA encryption failed: invalid public key', $this->getDriverName()));
        }

        $encrypted = '';
        if (!openssl_public_encrypt($data, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            throw new ApiException(sprintf('%s RSA encryption failed', $this->getDriverName()));
        }

        return bin2hex($encrypted);
    }

    /**
     * RSA-SHA256 簽名
     */
    protected function rsaSign(string $data, string $privateKey): string
    {
        // 確保私鑰格式正確
        $privateKey = $this->formatPrivateKey($privateKey);

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new ApiException(sprintf('%s RSA sign failed: invalid private key', $this->getDriverName()));
        }

        $signature = '';
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ApiException(sprintf('%s RSA sign failed', $this->getDriverName()));
        }

        return base64_encode($signature);
    }

    /**
     * 發送 Kira API 請求（含業務碼驗證，供代收等同步失敗即中止的流程）
     */
    protected function sendRequest(string $endpoint, array $data): array
    {
        $result = $this->sendRequestRaw($endpoint, $data);

        if (!isset($result['code']) || $result['code'] != 200) {
            throw new ApiException(
                sprintf('%s API error: %s', $this->getDriverName(), $result['message'] ?? 'Unknown error')
            );
        }

        return $this->decryptResultData($result);
    }

    /**
     * 發送 Kira API 請求（僅 HTTP 層檢查，不驗業務碼、不解密）。
     *
     * 供代付 performRequest 使用：只有 HTTP 非 2xx（結果未知）才拋例外，
     * 業務碼拒絕（code != 200）原樣回傳交 interpretResult 判定同步失敗，
     * 避免明確拒絕被誤判為結果未知而讓訂單停留在 21（PAYMENT_SUBMITTED）。
     */
    protected function sendRequestRaw(string $endpoint, array $data, ?string $token = [REDACTED] array
    {
        $url = $this->getApiUrl() . $endpoint;
        $symmetricKey = $this->info['CLIENT_SYMMETRIC_KEY'] ?? '';

        // 取得 Access Token（代付已前置取得並傳入，避免此處二次取 token 失敗誤停 21）
        $token ??= $this->getAccessToken();

        // 生成 nonce 和 timestamp
        $nonceStr = $this->generateNonceStr();
        $timestamp = (string) time();

        // 排序並加密請求數據
        $sortedData = $this->sortArrayKeysRecursive($data);
        $jsonData = Common::safeJsonEncode($sortedData);
        $base64Data = base64_encode($jsonData);
        $encryptedData = $this->aesEncrypt($base64Data, $symmetricKey);

        // 生成簽名
        $requestUrl = ltrim($endpoint, '/');
        $signature = $this->generateSignature(
            data: $data,
            requestPath: $requestUrl,
            nonceStr: $nonceStr,
            timestamp: $timestamp,
        );

        Log::debug($this->getDriverName() . ' API Request', [
            'url' => $url,
            'endpoint' => $endpoint,
            'data' => $this->maskSensitiveData($data),
        ]);

        // logAction 維持既有 'Kira sendRequest' 字面量（改名為 sendRequestRaw 後不可用 __FUNCTION__，
        // 否則既有 log 檢索／告警字串會斷）
        $response = Http::logAction($this->getDriverName() . ' sendRequest')
            ->timeout(30)
            ->asJson()
            ->withHeaders([
                'Authorization' => $token,
                'X-Nonce-Str' => $nonceStr,
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            ])
            ->post($url, ['data' => $encryptedData]);

        $result = $response->json();

        Log::debug($this->getDriverName() . ' API Response', [
            'url' => $url,
            'status' => $response->status(),
            'code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
        ]);

        if (!$response->successful()) {
            // 如果是 401，清除 token 快取
            if ($response->status() === 401) {
                $this->clearAccessToken();
            }

            throw new ApiException(
                sprintf('%s API error: HTTP %d', $this->getDriverName(), $response->status())
            );
        }

        return $result ?? [];
    }

    /**
     * 解密回應內層數據；無 encryptedData 或解不出 JSON 時原樣回傳
     */
    protected function decryptResultData(array $result): array
    {
        $symmetricKey = $this->info['CLIENT_SYMMETRIC_KEY'] ?? '';

        $encryptedResponse = $result['encryptedData'] ?? '';
        if (!empty($encryptedResponse)) {
            $decryptedJson = $this->aesDecrypt($encryptedResponse, $symmetricKey);
            $decryptedData = json_decode($decryptedJson, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decryptedData;
            }
        }

        return $result;
    }

    /**
     * 解密回調數據
     */
    protected function decryptCallbackData(string $encryptedData): array
    {
        $symmetricKey = $this->info['CLIENT_SYMMETRIC_KEY'] ?? '';

        if (empty($symmetricKey)) {
            throw new ApiException(sprintf('%s CLIENT_SYMMETRIC_KEY not configured', $this->getDriverName()));
        }

        $decryptedJson = $this->aesDecrypt($encryptedData, $symmetricKey);
        $data = json_decode($decryptedJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException(sprintf('%s callback data decryption failed: invalid JSON', $this->getDriverName()));
        }

        return $data;
    }

    /**
     * 生成隨機字串
     */
    protected function generateNonceStr(int $length = 16): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * 遞迴排序陣列 key
     */
    protected function sortArrayKeysRecursive(array $array): array
    {
        ksort($array);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->sortArrayKeysRecursive($value);
            }
        }

        return $array;
    }

    /**
     * 格式化公鑰
     */
    protected function formatPublicKey(string $key): string
    {
        $key = trim($key);

        // 如果已經有 PEM 格式的頭尾，直接返回
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        // 移除可能的換行符並重新格式化
        $key = str_replace(["\r", "\n", ' '], '', $key);

        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split($key, 64, "\n") .
            '-----END PUBLIC KEY-----';
    }

    /**
     * 格式化私鑰
     */
    protected function formatPrivateKey(string $key): string
    {
        $key = trim($key);

        // 如果已經有 PEM 格式的頭尾，直接返回
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        // 移除可能的換行符並重新格式化
        $key = str_replace(["\r", "\n", ' '], '', $key);

        return "[PRIVATE_KEY_BLOCK_REDACTED]';
    }

    /**
     * 隱遮敏感信息用於日誌
     */
    protected function maskSensitiveData(array $data): array
    {
        $sensitive = [
            'CLIENT_ID',
            'CLIENT_SECRET',
            'CLIENT_PRIVATE_KEY',
            'CLIENT_SYMMETRIC_KEY',
            'SERVER_PUBLIC_KEY',
            'signature',
            'Signature',
            'token',
            'Token',
        ];
        $result = $data;

        foreach ($sensitive as $key) {
            if (isset($result[$key])) {
                $result[$key] = '******';
            }
        }

        // 遞迴處理巢狀陣列
        foreach ($result as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->maskSensitiveData($value);
            }
        }

        return $result;
    }
}
