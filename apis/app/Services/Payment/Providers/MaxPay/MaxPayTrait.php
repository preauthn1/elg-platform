<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MaxPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait MaxPayTrait
{
    protected function getApiUrl(): string
    {
        return rtrim($this->info['API_URL'] ?? '', '/');
    }

    /**
     * 發送 MaxPay API 請求（含業務碼驗證，供代收等同步失敗即中止的流程）
     */
    protected function sendRequest(string $endpoint, array $data): array
    {
        $result = $this->sendRequestRaw($endpoint, $data);

        if (!isset($result['status']) || $result['status'] != true) {
            throw new ApiException(
                sprintf('%s API error: %s', $this->getDriverName(), $result['message'] ?? 'Unknown error')
            );
        }

        return $result;
    }

    /**
     * 發送 MaxPay API 請求（僅 HTTP 層檢查，不驗業務 status）。
     *
     * 供代付 performRequest 使用：只有 HTTP 非 2xx（結果未知）才拋例外，
     * 業務拒絕（status != true）原樣回傳交 interpretResult 判定同步失敗，
     * 避免明確拒絕被誤判為結果未知而讓訂單停留在 21（PAYMENT_SUBMITTED）。
     */
    protected function sendRequestRaw(string $endpoint, array $data): array
    {
        $apiUrl = $this->getApiUrl();
        $url = $apiUrl . $endpoint;

        Log::debug($this->getDriverName() . ' API Request', [
            'url' => $url,
            'endpoint' => $endpoint,
            'data' => Common::maskData($data),
        ]);

        $response = Http::logAction($this->getDriverName() . '_sendRequest')
            ->timeout(30)
            ->post($url, $data);

        $result = $response->json();

        Log::debug($this->getDriverName() . ' API Response', [
            'url' => $url,
            'status' => $response->status(),
            'code' => $result['status'] ?? null,
            'message' => $result['data'] ?? null,
        ]);

        if (!$response->successful()) {
            // 帶上三方 body 的 message（若有），保留代收路徑的診斷資訊
            throw new ApiException(trim(
                sprintf('%s API error: HTTP %d %s', $this->getDriverName(), $response->status(), $result['message'] ?? '')
            ));
        }

        return $result ?? [];
    }

    protected function generateHash(string $apiKey, string $refNo, string|float $amount, string $secretKey): string
    {
        return hash('sha512', $apiKey . $refNo . $amount . $secretKey);
    }
}
