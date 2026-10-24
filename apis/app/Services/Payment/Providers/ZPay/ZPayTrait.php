<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\ZPay;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait ZPayTrait
{
    protected string $apiUrlField = 'API_URL';

    protected string $tokenField = 'TOKEN';

    protected string $secretKeyField = 'SECRET_KEY';

    /**
     * 回調成功回應
     */
    protected function successResponse(): array
    {
        return [
            'status' => 'OK',
            'message' => 'Success',
        ];
    }

    /**
     * 回調失敗回應
     */
    protected function failResponse(): array
    {
        return [
            'status' => 'FAIL',
            'message' => 'Failed',
        ];
    }

    /**
     * 發送 Form POST 請求（Launch API）
     */
    protected function sendFormRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendFormRequest';
        $url = rtrim($this->info[$this->apiUrlField], '/') . $url;

        $response = Http::logAction($action)
            ->asForm()
            ->withHeaders($headers)
            ->post($url, $params);

        Log::info(sprintf('%s Form Request Response', $this->getDriverName()), [
            'url' => $url,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $result = $response->throw()->json();

        return $result;
    }

    /**
     * 發送 JSON POST 請求（Payout API）
     */
    protected function sendJsonRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendJsonRequest';
        $url = rtrim($this->info[$this->apiUrlField], '/') . $url;

        $response = Http::logAction($action)
            ->asJson()
            ->withHeaders($headers)
            ->post($url, $params);

        Log::info(sprintf('%s JSON Request Response', $this->getDriverName()), [
            'url' => $url,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $result = $response->throw()->json();

        return $result;
    }

    /**
     * 生成 ItemDesc（用於存款請求與保存）
     *
     * ItemDesc = MD5(order_no + amount + secret)
     */
    protected function generateItemDescSign(string $orderNo, string $amount, string $secret): string
    {
        return md5($orderNo . $amount . $secret);
    }

    /**
     * 生成代付 SecretParam 簽名
     */
    protected function generateSecretParamSign(string $referenceId, string $datetime, float $amount, string $secret): string
    {
        return md5($referenceId . $datetime . $amount . $secret);
    }

    /**
     * 驗證代付回調簽名
     */
    protected function verifyCallbackSign(
        string $referenceId,
        string $dateTime,
        float $amount,
        string $secret,
        string $receivedItemDesc
    ): bool {
        $expected = $this->generateSecretParamSign($referenceId, $dateTime, $amount, $secret);

        return hash_equals(strtolower($expected), strtolower($receivedItemDesc));
    }

    /**
     * 驗證回調 ItemDesc
     *
     * 回調 ItemDesc = MD5( stored_item_desc + secret )
     */
    protected function verifyCallbackItemDesc(
        string $storedItemDesc,
        string $secret,
        string $receivedItemDesc
    ): bool {
        $expected = md5($storedItemDesc . $secret);

        return hash_equals(strtolower($expected), strtolower($receivedItemDesc));
    }

    /**
     * 獲取商戶憑證
     */
    protected function getMerchantCredentials(): ZPayCredentials
    {
        $token = [REDACTED] ?? '';
        $secretKey = $this->info[$this->secretKeyField] ?? '';

        if ($token === '' || $secretKey === '') {
            throw new ApiException(sprintf('%s credentials are missing', $this->getDriverName()));
        }

        return new ZPayCredentials($token, $secretKey);
    }
}
