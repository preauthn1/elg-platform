<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\LegendPay;

use Illuminate\Support\Facades\Http;

trait LegendPayTrait
{
    // agentCashFlow->info 的 key 定義
    protected string $apiUrlField = 'API_URL';

    protected string $signField = 'sign';

    /**
     * 發送請求
     */
    protected function sendRequest(string $url, array $params, array $headers): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $url = rtrim($this->info[$this->apiUrlField], '/') . $url;

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
     */
    protected function generateSign(array $params, array $info): string
    {
        // 只移除 null 與空字串，保留 0 與 '0'
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // 若回傳欄位名稱為 signature，排除它
        unset($filtered[$this->signField]);

        ksort($filtered);

        // LegendPay 以解碼後的原始值簽名（實證：代付回調 message="IFSC err" 的三方 sign 為空格形式）。
        // http_build_query 將空格編為 +，必須用 urldecode 還原；rawurldecode 不還原 +，
        // 會使任何含空格的值（如 personName）簽名錯誤，三方回 1101 invalid sign。
        $sign = urldecode(http_build_query($filtered));
        $sign = $sign . '&key=' . $info['API_SECRET'];

        return strtoupper(md5($sign));
    }

    /**
     * 驗證簽名
     */
    protected function checkSign(array $params, array $info): bool
    {
        $sign = $params[$this->signField] ?? '';
        $expected = $this->generateSign($params, $info);

        return hash_equals($expected, $sign);
    }
}
