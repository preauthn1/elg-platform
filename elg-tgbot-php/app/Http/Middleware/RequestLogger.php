<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 紀錄每次請求與結果
 */
class RequestLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = Context::getHidden('REQUEST_ID');
        $fullUrl = $request->url();
        $method = $request->getMethod();
        $path = $request->path();

        // Log the start time
        $startTime = microtime(true);

        // 記錄所有請求
        $logRequest = config('switch.log_request');

        if (!$logRequest) {
            return $next($request);
        }

        // 記錄請求的上下文
        Log::withContext([
            'url' => $fullUrl,
            'method' => $method,
        ]);

        /**
         * 請求日誌
         */
        $inputData = $request->all();
        Log::debug($path . ' 請求紀錄 ' . $requestId, [
            'request' => $inputData,
        ]);

        // Initialize query count
        $queryCount = 0;

        // Listen for query events
        DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        // Proceed with the request
        $response = $next($request);

        // Log the end time and calculate the duration
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $duration = number_format($duration, 3, '.', '');

        // 如果是 API 接口返回 JSON 格式, 將響應結果轉換為陣列
        /** @var Response $response */
        $responseData = $response->getContent();
        if (str_contains($response->headers->get('Content-Type'), 'application/json')) {
            $responseData = json_decode($responseData, true);
        }

        /**
         * 響應日誌
         */
        $context = [
            'response' => $responseData,
            'duration' => $duration,
            'query_count' => $queryCount,
        ];

        Log::debug($path . ' 響應紀錄 ' . $requestId, $context);

        return $response;
    }
}
