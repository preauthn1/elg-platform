<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TngPin;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait TngPinTrait
{
    protected string $apiUrlField = 'APIUrl';

    protected string $signField = 'signature';

    /**
     * 發送請求
     */
    protected function sendRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';
        $fullUrl = rtrim($this->info[$this->apiUrlField], '/') . $url;

        try {
            // Telco PIN 屬不可逆扣款：第一次失敗就拋錯，禁止 retry
            // （即使是 timeout / 5xx，同 PIN 重送會被三方標記為 "already used"，導致玩家損失）
            // maskKeys：請求/回應 body 含 raw pin 與 API 憑證，遮罩後才寫 HTTP log（憑證屬金錢，log channel 比 DB 廣）
            $result = Http::logAction($action, maskKeys: ['payment_key', 'payment_pass', 'pin'])
                ->connectTimeout(30)
                ->timeout(90)
                ->asJson()
                ->withHeaders($headers)
                ->post($fullUrl, $params)
                ->throw()
                ->json();
        } catch (ConnectionException $e) {
            throw new ApiException(
                sprintf('Connection timeout to %s: %s', $fullUrl, $e->getMessage()),
                HttpCode::HTTP_GATEWAY_TIMEOUT->value,
                $e
            );
        } catch (RequestException $e) {
            $statusCode = $e->response?->status() ?? 0;
            $responseBody = $e->response?->body() ?? 'No response body';

            // 根據狀態碼選擇適當的錯誤碼
            $errorCode = match (true) {
                $statusCode === 408 => HttpCode::HTTP_REQUEST_TIMEOUT->value,
                $statusCode >= 500 => HttpCode::HTTP_BAD_GATEWAY->value,
                default => HttpCode::HTTP_BAD_REQUEST->value,
            };

            throw new ApiException(
                sprintf('Request failed to %s (HTTP %d): %s | Response: %s',
                    $fullUrl, $statusCode, $e->getMessage(), $responseBody),
                $errorCode,
                $e
            );
        }

        return $result;
    }

    /**
     * 驗證簽名
     */
    protected function checkSign(array $params, array $info): bool
    {
        $sign = $params[$this->signField] ?? '';
        // 官方簽名公式第三段為三方回傳的 merchant_ref（md5(payment_key + transaction_id + merchant_ref)）。
        // 必須取回傳值、不可寫死 info['Merchant']：本系統送出的 merchant_ref 已對齊舊系統 / 工單 #3865 改為
        // order.order_no（每單唯一），三方回什麼簽什麼；同時相容在途舊單（舊單以商戶號簽章亦取其回傳值驗證）。
        $expected = md5($info['ApiKey'] . $params['transaction_id'] . ($params['merchant_ref'] ?? ''));

        return hash_equals($expected, $sign);
    }

    /**
     * 遮罩敏感欄位後回傳副本，供寫入 remark / log 使用（不修改原陣列）。
     *
     * - payment_key / payment_pass：API 憑證，全遮罩。
     * - pin：支付憑證，沿用 maskPin() 保留末 5 碼供人工對帳。
     *
     * 對齊舊系統 TngpinPay::maskSensitive：禁止將 PIN 與 API 憑證以明文寫入 DB / log。
     */
    protected function maskSensitive(array $data): array
    {
        foreach (['payment_key', 'payment_pass'] as $key) {
            if (isset($data[$key]) && (string) $data[$key] !== '') {
                $data[$key] = '***';
            }
        }

        if (isset($data['pin']) && (string) $data['pin'] !== '') {
            $data['pin'] = $this->maskPin((string) $data['pin']);
        }

        return $data;
    }

    /**
     * PIN 遮罩：保留末 5 碼供人工對帳，其餘以 * 取代。PIN 屬支付憑證，禁止寫入 log/BotNotify 原文。
     */
    protected function maskPin(string $pin): string
    {
        $len = strlen($pin);
        if ($len <= 5) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 5) . substr($pin, -5);
    }
}
