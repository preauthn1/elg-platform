<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AkPay;

use Illuminate\Support\Facades\Http;

trait AkPayTrait
{
    /**
     * 發送請求
     */
    protected function sendRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $url = rtrim($this->info['API_URL'], '/') . $url;

        $result = Http::logAction($action)
            ->asJson()
            ->withToken($this->info['MERCHANT_TOKEN'])
            ->withHeaders($headers)
            ->post($url, $params)
            ->json();

        return $result;
    }

    /**
     * 生成簽名
     */
    protected function generateSecretKey(array $params, string $token): string
    {
        $encrypt = [
            'source_id' => $params['source_id'],
            'tx_id' => $params['tx_id'],
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'token' => $token,
        ];

        // lad(http_build_query($encrypt));

        return md5(urldecode(http_build_query($encrypt)));
    }

    /**
     * 驗證簽名
     */
    protected function checkSecretKey(array $params, string $token): bool
    {
        $secretKey = $this->generateSecretKey($params, $token);

        // lad($params['secret_key']);
        // lad($secretKey);
        return hash_equals($params['secret_key'], $secretKey);
    }
}
