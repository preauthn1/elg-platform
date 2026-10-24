<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MajesticPay;

use Illuminate\Support\Facades\Http;

trait MajesticPayTrait
{
    protected string $apiUrlField = 'API_URL';

    protected string $signField = 'hash';

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
     * (SHA 256) Hash Format: ref_no + amount + currency
     */
    protected function generateHash(array $params, string $secretKey = ''): string
    {
        $stringToSign = $params['ref_no'] . number_format((float) $params['amount'], 2, '.', '') . $params['currency'];

        return hash_hmac('sha256', $stringToSign, $secretKey);
    }

    protected function checkHash(array $input, string $secretKey = ''): bool
    {
        $stringToSign = $input['ref_no'] . $input['amount'] . $input['currency'];
        $calculatedSign = hash_hmac('sha256', $stringToSign, $secretKey);

        return hash_equals($calculatedSign, $input[$this->signField]);
    }
}
