<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\YeePay;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait YeePayTrait
{
    protected string $signField = 'sign';

    /**
     * 發送請求
     */
    protected function sendRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $result = Http::logAction($action)
            ->asForm()
            ->withHeaders($headers)
            ->post($url, $params)
            ->throw()
            ->json();

        return $result;
    }

    /**
     * 生成簽名
     *
     * 簽名規則：
     * 1. 對請求參數進行 ASCII 升序排序
     * 2. 排除參數值為 0、空或 null 的
     * 3. 對參數進行拼接 如 key1=value1&key2=value2，最後再末尾拼接上簽名 key
     * 4. 對字串進行 MD5 加密，加密後的字串字母全部轉大寫
     *
     * @param  array  $params  請求參數
     * @param  string  $secret  密鑰
     * @return string 簽名字串（大寫 MD5）
     */
    protected function generateSign(array $params, string $secret): string
    {
        // 排除 sign 欄位
        unset($params[$this->signField]);

        // 第二步：排除參數值為 0、空或 null 的
        $filtered = array_filter($params, fn ($value) => !in_array($value, [null, '', 0, '0'], true)
        );

        // 第一步：對請求參數進行 ASCII 升序排序
        ksort($filtered);

        // 第三步：對參數進行拼接 key1=value1&key2=value2&key=secret
        $queryString = urldecode(http_build_query($filtered));
        $signString = $queryString . '&key=' . $secret;

        // 調試信息（生產環境可移除）
        // Log::debug('YeePay Sign String  => ' . $signString);

        // 第四步：MD5 加密後轉大寫
        return strtoupper(md5($signString));
    }

    /**
     * 驗證簽名
     */
    protected function checkSign(array $params, string $secret): bool
    {
        $sign = $params[$this->signField] ?? '';

        if (empty($secret)) {
            throw new ApiException('Secret key not found in info', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        $expected = $this->generateSign($params, $secret);

        return hash_equals($expected, $sign);
    }
}
