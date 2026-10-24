<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GMPay;

use Illuminate\Support\Facades\Http;

/**
 * GMPay 專用 Trait（standalone，不繼承 MajesticPayTrait）。
 *
 * GMPay 與 MajesticPay 已於工單 #2435 起分歧、走向各自獨立實作；本 trait 自帶
 * HTTP 發送與簽名邏輯，與 MajesticPay 完全解耦。與 MajesticPay 的唯一協議差異在
 * 回調驗簽拼接：GMPay 用 `transaction_reference + amount + currency`（MajesticPay 用 `ref_no + ...`）。
 */
trait GMPayTrait
{
    protected string $apiUrlField = 'API_URL';

    protected string $signField = 'hash';

    /**
     * 發送請求
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function sendRequest(string $url, array $params, array $headers = []): array
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
     * 發起簽名（代收/代付共用）。
     * (SHA 256) Hash Format: ref_no + amount + currency（金額格式化為兩位小數）
     *
     * @param  array<string, mixed>  $params
     */
    protected function generateHash(array $params, string $secretKey = ''): string
    {
        $stringToSign = $params['ref_no'] . number_format((float) $params['amount'], 2, '.', '') . $params['currency'];

        return hash_hmac('sha256', $stringToSign, $secretKey);
    }

    /**
     * 回調驗簽(GMPay 專屬)：拼接 transaction_reference + amount + currency。
     *
     * @param  array<string, mixed>  $input
     */
    protected function checkHash(array $input, string $secretKey = ''): bool
    {
        $stringToSign = $input['transaction_reference'] . $input['amount'] . $input['currency'];
        $calculatedSign = hash_hmac('sha256', $stringToSign, $secretKey);

        return hash_equals($calculatedSign, $input[$this->signField]);
    }
}
