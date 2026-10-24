<?php

namespace App\Custom;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use Bilfeldt\LaravelHttpClientLogger\HttpLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpLoggerCustom extends HttpLogger
{
    public function log(
        RequestInterface $request,
        ResponseInterface $response,
        float $sec,
        array $context = [],
        array $config = []
    ): void {
        parent::log($request, $response, $sec, $context, $config);
        $this->cleanUpContext();
    }

    protected function getMessage(): string
    {
        $body = $this->request->getBody();
        $body->rewind(); // 確保從頭開始讀取
        $rawContent = $body->getContents();

        $parameters = json_decode($rawContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 如果不是 JSON，嘗試解析為字串格式 (Http::asForm())
            parse_str($rawContent, $parameters);
        }

        $requestContext = [
            'url' => $this->request->getUri()->__toString(),
            'method' => $this->request->getMethod(),
            'header' => Common::flattenHeaders($this->request->getHeaders()),
            'parameters' => $parameters,
        ];

        $body = $this->response->getBody();
        $body->rewind(); // 確保從頭開始讀取
        $rawContent = $body->getContents();
        $body = json_decode($rawContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $body = $rawContent;
        }

        $responseContext = [
            'header' => Common::flattenHeaders($this->response->getHeaders()),
            'statusCode' => $this->response->getStatusCode(),
            'body' => $body,
        ];

        Context::addHidden(ContextKeyEnum::VENDOR_REQUEST->value, $requestContext);
        Context::addHidden(ContextKeyEnum::VENDOR_RESPONSE->value, $responseContext);

        Log::withContext(
            [
                ContextKeyEnum::VENDOR_REQUEST->value => $requestContext,
                ContextKeyEnum::VENDOR_RESPONSE->value => $responseContext,
            ]
        );

        $actionKey = ContextKeyEnum::LOG_HTTP_ACTION->value;
        $action = Context::getHidden($actionKey) ?? $requestContext['url'];
        Context::forgetHidden($actionKey);

        return $action . ', Time ' . Common::numFloor($this->sec, 5) . ' sec';
    }

    protected function cleanUpContext(): void
    {
        Log::withoutContext([
            ContextKeyEnum::VENDOR_RESPONSE->value,
            ContextKeyEnum::VENDOR_RESPONSE->value,
        ]);
    }
}
