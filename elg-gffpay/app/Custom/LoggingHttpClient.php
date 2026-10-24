<?php

namespace App\Custom;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use Illuminate\Support\Facades\Context;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class LoggingHttpClient implements ClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {

        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);
        // Log request
        $body = $request->getBody();
        $bodyText = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $url = (string) $request->getUri();
        $jsonBody = json_decode($bodyText, true);
        $action = $jsonBody['method'] ?? '';
        $headers = Common::flattenHeaders($request->getHeaders());
        $requestLog = [
            'method' => $request->getMethod(),
            'url' => $url,
            'headers' => $headers,
            'body' => $bodyText,   // safe
        ];

        $logTitle = 'TonGateway Request ' . $action . ' ' . $requestId;

        try {
            $response = $this->client->sendRequest($request);
            $body = $response->getBody();
            $bodyText = (string) $body;
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $headers = Common::flattenHeaders($response->getHeaders());
            $responseLog = [
                'status' => $response->getStatusCode(),
                'headers' => $headers,
                'body' => $bodyText,  // safe
            ];
            // 成功的話, 請求跟回應一起記錄到 log
            $this->logger->info($logTitle, ['request' => $requestLog, 'response' => $responseLog]);
        } catch (Throwable $e) {
            // 失敗的話, 記錄請求跟錯誤
            $this->logger->error($logTitle, ['request' => $requestLog, 'error' => $e->getMessage(), 'exception' => $e]);
            throw $e;
        }

        return $response;
    }
}
