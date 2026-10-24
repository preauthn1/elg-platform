<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\General\ContextKeyEnum;
use App\Facades\BotNotify;
use App\Helpers\Common;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);
        $fullUrl = $request->url();
        $method = $request->getMethod();
        $host = $request->getSchemeAndHttpHost();
        $path = $request->path();

        // Log the start time
        $startTime = microtime(true);

        // 記錄所有請求
        $logRequest = config('custom.log_request');

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
        $inputData = Common::maskData($request->all());
        $requestLog = [
            'header' => Common::flattenHeaders($request->headers->all()),
            'request' => $inputData,
        ];
        Log::info($path . ' 請求紀錄 ' . $requestId, $requestLog);

        // Initialize query count
        $queryCount = 0;
        $sqlQueries = [];
        // Listen for query events
        DB::listen(function ($query) use (&$queryCount, &$sqlQueries) {
            $queryCount++;
            // 最多只紀錄 50 條 SQL 語句
            if ($queryCount <= config('custom.alarm.max_sql_logs')) {
                $entry = [
                    'sql' => Common::queryToSql($query->sql, $query->bindings),
                    'time' => $query->time / 1000,
                ];

                if (config('app.debug') === true) {
                    // 在 debug 模式下，記錄 SQL 語句和呼叫堆疊取得語法執行位置
                    $entry['file'] = Common::getQueryCallerFile();
                }
                // lad($entry);
                $sqlQueries[] = $entry;
            }
        });

        // Proceed with the request
        $response = $next($request);

        // Log the end time and calculate the duration
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $duration = Common::numFloor($duration, 3);

        // 如果是 API 接口返回 JSON 格式, 將響應結果轉換為陣列
        /** @var Response $response */
        $responseData = $response->getContent();

        // 嘗試解析 JSON
        $responseData = json_decode($responseData, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $responseData['data'] = Common::maskData($responseData['data'] ?? []);
            $responseData = $this->trimIndexedArrays($responseData);
        }

        /**
         * 響應日誌
         */
        $responseLog = [
            'header' => Common::flattenHeaders($response->headers->all()),
            'response' => $responseData,
            'duration' => $duration,
            'query_count' => $queryCount,
        ];

        Log::info($path . ' 響應紀錄 ' . $requestId, $responseLog);

        $warnInfo = [
            'duration' => $duration,
            'query_count' => $queryCount,
            'host' => $host,
            'path' => $path,
            'request' => $inputData,
            'request_id' => $requestId,
        ];
        // 告警
        if ($duration > config('custom.alarm.query_duration')) {
            Log::emergency("{$path} 響應過慢 {$duration} sec, {$requestId}", $warnInfo);
            Log::emergency("{$path} 查詢語法 {$requestId}", ['queries' => $sqlQueries]);
            BotNotify::channel('warn')->send('響應過慢', $warnInfo);
        }
        // TODO 每個請求都有7次基本查詢, 看看能不能上緩存
        if ($queryCount > config('custom.alarm.query_count')) {
            Log::emergency("{$path} 查詢數過高 {$queryCount}, {$requestId}", $warnInfo);
            Log::emergency("{$path} 查詢語法 {$requestId}", ['queries' => $sqlQueries]);
            BotNotify::channel('warn')->send('查詢數過高', $warnInfo);
        }

        return $response;
    }

    /**
     * 縮減陣列 只保留前 5 個元素
     */
    private function trimIndexedArrays(array $data): array
    {
        $maxLength = 5;

        if (Arr::isAssoc($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = $this->trimIndexedArrays($value);
                }
            }
        } elseif (count($data) > $maxLength) {
            $data = array_slice($data, 0, $maxLength);
        }

        return $data;
    }
}
