<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VtPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait VtPayTrait
{
    protected string $signField = 'sign';

    protected VtPayCryptoService $cryptoService;

    /**
     * 取得加密服務實例
     */
    protected function getCryptoService(): VtPayCryptoService
    {
        if (!isset($this->cryptoService)) {
            $this->cryptoService = new VtPayCryptoService;
        }

        return $this->cryptoService;
    }

    /**
     * 發送請求（包含 AES 加密）
     *
     * 參照官方範例：PHP_example_encrypt_decrypt.php
     *
     * VtPay 加密規則：
     * - 請求：加密發送（Base64 json 包含 iv, value）
     * - 響應：通常為加密返回，需解密
     * - 回調：需要解密
     *
     * curl -X POST {URL}/SH/{endpoint} -d "加密字串"
     */
    protected function sendRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';
        $secret = [REDACTED]'API_SECRET'];

        try {
            Log::info($this->getDriverName() . ' preparing request', [
                'url' => $url,
                'params' => Common::maskData($params),
            ]);

            // AES 加密請求
            $encryptedData = $this->getCryptoService()->encrypt($params, $secret);

            // 發送加密數據：直接作為 raw body 發送
            // 等同於：curl -d "加密字串"
            $response = Http::logAction($action)
                ->withHeaders($headers)
                ->withBody($encryptedData, 'text/plain')
                ->post($url)
                ->throw()
                ->body();

            // 解析並解密響應
            return $this->decrypt($response, 'response');
        } catch (Exception $e) {
            Log::error($this->getDriverName() . ' request failed', [
                'error' => $e->getMessage(),
                'url' => $url,
                'params' => Common::maskData($params),
                'trace' => Common::formatTrace($e),
            ]);
            throw $e;
        }
    }

    /**
     * 解密數據（適用於 API 響應與回調）
     *
     * @param  string  $encryptedData  加密字串
     * @param  string  $context  來源上下文 (response/callback)
     *
     * @throws ApiException
     */
    protected function decrypt(string $encryptedData, string $context = 'callback'): array
    {
        $secret = [REDACTED]'API_SECRET'];

        // 若 $encryptedData 不是 JSON 或 base64 編碼，通常是三方報錯訊息, 直接返回錯誤訊息
        $base64EncryptedData = base64_decode($encryptedData, true);
        $jsonEncryptedData = json_decode($base64EncryptedData, true);
        if (json_last_error() != JSON_ERROR_NONE || !is_array($jsonEncryptedData)) {
            throw new ApiException(sprintf('third party error: %s', $encryptedData));
        }

        try {
            $result = $this->getCryptoService()->decrypt($encryptedData, $secret);

            Log::info(sprintf('%s %s decrypted', $this->getDriverName(), $context), [
                'result' => $result,
            ]);

            // [
            //     'order_status' => 5,
            //     'sh_order_no' => 'AK2026020908440636594',
            //     'order_amount' => 100.0,
            //     'paid_amount' => 0.0,
            // ]
            return $result;
        } catch (Exception $e) {
            Log::error(sprintf('%s %s decryption failed', $this->getDriverName(), $context), [
                'error' => $e->getMessage(),
                'encrypted_data' => $encryptedData,
            ]);

            throw $e;
        }
    }

    /**
     * 生成回調響應（加密）
     */
    protected function encryptCallbackResponse(array $data): string
    {
        $secret = [REDACTED]'API_SECRET'];

        return $this->getCryptoService()->encrypt($data, $secret);

    }
}
