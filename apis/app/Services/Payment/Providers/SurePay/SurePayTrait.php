<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\SurePay;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait SurePayTrait
{
    /**
     * 必要的配置欄位
     */
    private const array REQUIRED_CONFIG_KEYS = [
        'API_URL',
        'MERCHANT_ID',
        'CONTRACT_NAME',
        'API_KEY',
        'CALLBACK_API_KEY',
        'CURRENCY',
    ];

    // 欄位名稱常數
    private const string FIELD_API_URL = 'API_URL';

    private const string FIELD_API_KEY = [REDACTED]';

    private const string FIELD_CALLBACK_API_KEY = [REDACTED]';

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
            ->post($url, $data)
            ->json();

        return $result;
    }

    /**
     * 生成 SurePay 簽名（用於發送請求）
     *
     * @param  array  $params  包含簽名所需參數的陣列
     * @return string MD5 加密後的簽名
     */
    protected function generateToken(array $params): string
    {
        $string = $params['merchant'] .
            $params['amount'] .
            $params['refid'] .
            $params['customer'] .
            $this->info[self::FIELD_API_KEY] .
            $params['currency'] .
            $params['clientip'];

        return md5($string);
    }

    protected function generateCallbackToken(array $params): string
    {
        $string = $params['merchant'] .
            $params['amount'] .
            $params['status'] .
            $this->info[self::FIELD_CALLBACK_API_KEY] .
            $params['trxno'];

        return md5($string);
    }

    /**
     * 驗證 SurePay 回調簽名
     *
     * @param  array  $params  回調參數
     * @return bool 驗證結果
     */
    protected function checkToken(array $params): bool
    {
        $requestToken = [REDACTED]'token'];

        $token = [REDACTED];

        if ($requestToken !== $token) {
            Log::debug($this->getDriverName() . ' checkToken failed', [
                'request_token' => $requestToken,
                'token' => $token,
            ]);

            return false;
        }

        return true;
    }
}
