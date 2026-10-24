<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TgPay;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait TgPayTrait
{
    /**
     * 必要的配置欄位
     */
    private const array REQUIRED_CONFIG_KEYS = [
        'API_URL',
        'PUBLIC_KEY',
        'SECRET_KEY',
    ];

    private const string FIELD_API_URL = 'API_URL';

    private const string FIELD_PUBLIC_KEY = 'PUBLIC_KEY';

    private const string FIELD_SECRET_KEY = [REDACTED]';

    /**
     * 驗證必要配置是否存在
     *
     * @throws ApiException 當缺少必要配置時
     */
    protected function validateConfig(): void
    {
        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            if (!isset($this->info[$key]) || empty($this->info[$key])) {
                throw new ApiException(
                    sprintf('%s deposit error: Missing required config key: %s', $this->getDriverName(), $key)
                );
            }
        }
    }

    /**
     * 發送請求
     */
    protected function sendRequest(string $endpoint, array $data): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $url = rtrim($this->info[self::FIELD_API_URL], '/') . $endpoint;

        $result = Http::logAction($action)
            ->asJson()
            ->withHeaders($this->buildRequestHeaders())
            ->post($url, $data)
            ->json();

        return $result;
    }

    /**
     * @return array<string, string>
     */
    protected function buildRequestHeaders(): array
    {
        return [
            'x-public-key' => $this->info[self::FIELD_PUBLIC_KEY],
            'x-secret-key' => $this->info[self::FIELD_SECRET_KEY],
        ];
    }

    /**
     * 驗證回調請求 header 與商戶配置一致
     */
    protected function verifyCallbackHeaders(Request $request): bool
    {
        $publicKey = (string) $request->header('x-public-key', '');
        $secretKey = (string) $request->header('x-secret-key', '');

        $expectedPublicKey = (string) $this->info[self::FIELD_PUBLIC_KEY];
        $expectedSecretKey = (string) $this->info[self::FIELD_SECRET_KEY];

        if (
            hash_equals($expectedPublicKey, $publicKey)
            && hash_equals($expectedSecretKey, $secretKey)
        ) {
            return true;
        }

        Log::warning($this->getDriverName() . ' callback header verification failed', [
            'x-public-key_match' => hash_equals($expectedPublicKey, $publicKey),
            'x-secret-key_match' => hash_equals($expectedSecretKey, $secretKey),
        ]);

        return false;
    }
}
