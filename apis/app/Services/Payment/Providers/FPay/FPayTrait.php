<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FPay;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait FPayTrait
{
    /**
     * 取得驗證碼
     */
    protected function getAuthCode(): string
    {
        $action = $this->getDriverName() . '_getAuthCode';

        $url = rtrim($this->info['API_URL'], '/') . '/merchant/auth';

        $data = [
            'username' => $this->info['MERCHANT_NO'],
            'api_key' => $this->info['APP_KEY'],
        ];

        try {
            $result = Http::logAction($action)
                ->asForm()
                ->post($url, $data)
                ->throw()
                ->json();
        } catch (Exception $e) {
            Log::error($action . ' Error:', ['url' => $url, 'data' => $data, 'error' => $e->getMessage()]);
            throw $e;
        }

        if ($result['status'] === true && isset($result['auth'])) {
            return $result['auth'];
        }

        throw new ApiException('Failed to get auth code: ' . ($result['message'] ?? 'Unknown error'), HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
    }

    /**
     * 發送請求
     */
    protected function sendRequest(string $url, array $data, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $url = rtrim($this->info['API_URL'], '/') . $url;

        try {
            $result = Http::logAction($action)
                ->asForm()
                ->withHeaders($headers)
                ->post($url, $data)
                ->throw()
                ->json();
        } catch (Exception $e) {
            Log::error($action . ' Error:', ['url' => $url, 'data' => $data, 'error' => $e->getMessage()]);
            throw $e;
        }

        return $result;
    }

    /**
     * 生成 Token
     */
    protected function generateToken(string $secret, string $orderId): string
    {
        return md5($secret . $orderId);
    }
}
