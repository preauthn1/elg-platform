<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FlashPay;

use Illuminate\Support\Facades\Http;

trait FlashPayTrait
{
    protected string $apiUrlField = 'API_URL';

    protected string $signField = 'tran_signature';

    protected function sendRequest(string $endpoint, array $params, array $headers = []): array
    {
        $url = rtrim($this->info[$this->apiUrlField], '/') . $endpoint;

        return Http::logAction($this->getDriverName() . '_sendRequest')
            ->asJson()
            ->withHeaders($headers)
            ->post($url, $params)
            ->throw()
            ->json();

    }

    protected function generateSign(array $params, string $secret): string
    {
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        unset($filtered[$this->signField]);

        ksort($filtered);

        $signStr = urldecode(http_build_query($filtered));

        return hash('sha256', $signStr . $secret);
    }

    protected function checkSign(array $params, string $secret): bool
    {
        $sign = $params[$this->signField] ?? '';
        $expected = $this->generateSign($params, $secret);

        return hash_equals($expected, $sign);
    }
}
