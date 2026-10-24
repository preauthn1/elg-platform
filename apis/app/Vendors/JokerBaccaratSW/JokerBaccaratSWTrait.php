<?php

declare(strict_types=1);

namespace App\Vendors\JokerBaccaratSW;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

trait JokerBaccaratSWTrait
{
    protected function buildSignature(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ApiException('[' . $this->vendorLabel . '] signature payload json encode failed');
        }

        $key = md5($this->infoDetail->API_TOKEN, true);
        $key24 = $key . substr($key, 0, 8);

        $encrypted = openssl_encrypt(
            $json,
            'des-ede3',
            $key24,
            OPENSSL_RAW_DATA,
            '',
        );

        if ($encrypted === false) {
            throw new ApiException('[' . $this->vendorLabel . '] signature encrypt failed');
        }

        return base64_encode($encrypted);
    }

    protected function sendRequest(string $endpoint, array $payload): array
    {
        $url = rtrim($this->infoDetail->API_URL, '/') . $endpoint;
        $action = $this->vendorLabel . '_' . str_replace('/', '_', trim($endpoint, '/'));

        // 簽名前的 raw payload 也記錄一筆，HTTP log 只看得到加密後 signature，
        // 三方端拒絕時需要原始 payload 才能比對 / 提供給對接窗口。
        // password 等敏感欄位脫敏（registerPlayer 會帶 password）。
        $loggablePayload = $payload;
        if (isset($loggablePayload['password'])) {
            $loggablePayload['password'] = '***';
        }
        Log::info('[' . $this->vendorLabel . '] sendRequest payload', [
            'endpoint' => $endpoint,
            'payload' => $loggablePayload,
        ]);

        $body = [
            'apiID' => $this->infoDetail->API_ID,
            'signature' => $this->buildSignature($payload),
        ];

        try {
            $response = Http::logAction($action)
                ->asJson()
                ->post($url, $body)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            $context = [
                'endpoint' => $endpoint,
                'payload' => $loggablePayload,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ];

            // Laravel RequestException::getMessage() 預設只截前 120 字，完整 response body 需另外取得
            if ($e instanceof RequestException && $e->response !== null) {
                $context['response_status'] = $e->response->status();
                $context['response_body'] = $e->response->body();
            }

            Log::error('[' . $this->vendorLabel . '] sendRequest failed', $context);
            throw $e;
        }

        if (!is_array($response)) {
            throw new ApiException('[' . $this->vendorLabel . '] invalid response: not array');
        }

        return $response;
    }
}
