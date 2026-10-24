<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayPro;

use App\Services\Payment\Traits\DriverTrait;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait PayProTrait
{
    use DriverTrait;

    // agentCashFlow->info 的 key 定義
    protected string $apiUrlField = 'API_URL';

    protected string $merchantNoField = 'MERCHANT_NO';

    protected string $merchantKeyField = 'MERCHANT_KEY';

    /**
     * 發送請求
     */
    protected function sendRequest(string $url, array $params = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $url = rtrim($this->info[$this->apiUrlField], '/') . $url;

        // 生成簽名
        $params['sign'] = $this->generateSign($params);

        $headers = [
            'merchantNo' => $this->info[$this->merchantNoField],
        ];

        try {
            $result = Http::logAction($action)
                ->asJson()
                ->withHeaders($headers)
                ->post($url, $params)
                ->throw()
                ->json();
        } catch (Exception $e) {
            Log::error($this->getDriverName() . ' HTTP 請求失敗', [
                'error' => $e->getMessage(),
                'request' => [
                    'url' => $url,
                    'params' => $this->maskSensitiveParams($params),
                    'headers' => $headers,
                ],
            ]);

            throw $e;
        }

        return $result ?? [];
    }

    /**
     * 生成簽名
     * 根據 PayPro 文檔：
     * 1. 所有參數按首字母 a-z 排序
     * 2. 拼接成 key1=value1key2=value2...
     * 3. 使用 app secret 作為 salt 進行 MD5 加密
     * 4. 轉小寫
     */
    protected function generateSign(array $params): string
    {
        // 排除 sign 欄位
        unset($params['sign']);

        // 過濾空值（但保留 0 和 '0'）
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // 按首字母 a-z 排序
        ksort($filtered);

        // 拼接成 key1=value1key2=value2...
        $signString = '';
        foreach ($filtered as $key => $value) {
            $signString .= $key . '=' . $value;
        }

        // 使用 merchant key 作為 salt
        $merchantKey = $this->info[$this->merchantKeyField] ?? '';
        $signString .= $merchantKey;

        // MD5 加密並轉小寫
        return strtolower(md5($signString));
    }

    /**
     * 驗證簽名
     */
    protected function verifySign(array $params): bool
    {
        $sign = $params['sign'] ?? '';

        if (empty($sign)) {
            return false;
        }

        $expectedSign = $this->generateSign($params);

        return hash_equals($expectedSign, $sign);
    }

    /**
     * 遮罩敏感參數用於日誌
     */
    protected function maskSensitiveParams(array $params): array
    {
        $masked = $params;
        if (isset($masked['sign'])) {
            $masked['sign'] = '***';
        }

        return $masked;
    }

    /**
     * 獲取時間戳（毫秒）
     */
    protected function getTimestamp(): string
    {
        return (string) (int) (microtime(true) * 1000);
    }
}
