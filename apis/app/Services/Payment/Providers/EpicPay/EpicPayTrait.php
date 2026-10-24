<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EpicPay;

use Illuminate\Support\Facades\Http;

trait EpicPayTrait
{
    // agentCashFlow->info 的 key 定義
    protected string $apiUrlField = 'API_URL';

    protected string $signField = 'sign';

    /**
     * 發送請求
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
     * Format: md5(<MERCHANT CODE>-<SECRET KEY>-<ACTION NAME>-<TIMESTAMP TS>)
     */
    protected function generateToken(array $params, string $action, string $secret): string
    {
        return md5($params['mCode'] . '-' . $secret . '-' . $action . '-' . $params['ts']);
    }

    /**
     * Format: md5(<MERCHANT CODE>-<SECRET KEY>-<TRANSACTION ID>-<AMOUNT>-<TIMESTAMP TS>)
     */
    protected function generateCallbackToken(array $params, string $merchant, string $secret): string
    {
        return md5($merchant . '-' . $secret . '-' . $params['id'] . '-' . $params['amount'] . '-' . $params['ts']);
    }
}
