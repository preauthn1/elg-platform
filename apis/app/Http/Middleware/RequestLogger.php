<?php

declare(strict_types=1);

/** @noinspection D */

namespace App\Http\Middleware;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Facades\BotNotify;
use App\Helpers\Common;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 紀錄每次請求與結果
 */
class RequestLogger
{
    /**
     * 不需要警告的請求路徑
     */
    protected array $skipAlarmPaths = [
        'frontend/api/member/transfer',
        'frontend/api/deposit/create-order',
        'frontend/api/member/transfer-all',
        'frontend/api/guest/register',
        'frontend/api/guest/login',
        'frontend/api/tg-bot/web-app-login',
        'frontend/api/tg-bot/oauth-login',
        'external/*',
    ];

    /**
     * 只遮硬 bearer 憑證（pin/卡號/銀行帳號等）、保留其餘欄位的路徑前綴。
     *
     * external 三方回調需保留完整 raw body 供除錯／驗簽（例如 token 欄位常是三方簽名本體），
     * 但 PIN／卡號等支付憑證仍必須遮罩。故此類路徑套 {@see Common::maskCredentials()}
     * 而非完整 {@see Common::maskData()}（後者會連 token/mobile/email 一起遮）。
     */
    protected array $credentialMaskOnlyPaths = [
        'external/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);
        $fullUrl = $request->url();
        $method = $request->getMethod();
        $host = $request->getSchemeAndHttpHost();
        $path = $request->path();

        // 使用高精度單調時鐘，避免系統時間調整造成耗時異常
        $startTime = hrtime(true);

        // 記錄所有請求
        $logRequest = config('custom.log_request');

        if (!$logRequest) {
            return $next($request);
        }

        // 記錄請求的上下文（external 路由只遮硬憑證、保留其餘欄位供除錯／驗簽）
        $inputData = $this->usesCredentialMaskOnly($path)
            ? Common::maskCredentials($request->all())
            : Common::maskData($request->all());

        /**
         * 請求日誌
         */
        $requestLog = [
            'request' => [
                'url' => $fullUrl,
                'method' => $method,
                'header' => Common::flattenHeaders($request->headers->all()),
                'params' => $inputData,
            ],
        ];

        Log::info($path . ' 請求紀錄', $requestLog);

        // Initialize query count
        $queryCount = 0;
        $sqlQueries = [];
        // Listen for query events
        DB::listen(function ($query) use (&$queryCount, &$sqlQueries) {
            // 最多只紀錄 50 條 SQL 語句
            if ($queryCount <= config('custom.alarm.max_sql_logs')) {
                $entry = [
                    'sql' => Common::queryToSql($query->sql, $query->bindings),
                    'time' => $query->time / 1000,
                ];

                if (config('app.debug')) {
                    // 在 debug 模式下，記錄 SQL 語句和呼叫堆疊取得語法執行位置
                    $file = Common::getQueryCallerFile();
                    if ($file) {
                        $entry['file'] = $file;
                    }
                }
                // lad($entry);
                $sqlQueries[] = $entry;
            }
            $queryCount++;
        });

        // Proceed with the request
        $response = $next($request);

        // 以毫秒整數記錄耗時（例如 6 秒 => 6000）
        $endTime = hrtime(true);
        $duration = (int) intdiv($endTime - $startTime, 1_000_000);
        $queryDurationAlarmMs = (int) round((float) config('custom.alarm.query_duration') * 1000);

        // 如果是 API 接口返回 JSON 格式, 將響應結果轉換為陣列
        /** @var Response $response */
        $statusCode = $response->getStatusCode();
        $responseData = $response->getContent();

        // 嘗試解析 JSON
        $decodedData = json_decode($responseData, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // external 路由只遮硬憑證、保留其餘欄位
            $decodedData['data'] = $this->usesCredentialMaskOnly($path)
                ? Common::maskCredentials($decodedData['data'] ?? [])
                : Common::maskData($decodedData['data'] ?? []);
            $responseData = $this->trimIndexedArrays($decodedData);
        } else {
            // 如果不是 JSON，截取前 1000 個字元避免日誌過大
            $responseData = Str::limit($responseData, 1000);
        }

        /**
         * 響應日誌
         */
        $responseLog = [
            'request' => [
                'url' => $fullUrl,
                'method' => $method,
                // 'header' => Common::flattenHeaders($request->headers->all()),
                'params' => $inputData,
            ],
            'response' => [
                'status_code' => $statusCode,
                // 'header' => Common::flattenHeaders($response->headers->all()),
                'data' => $responseData,
                'duration' => $duration,
                'query_count' => $queryCount,
            ],
        ];

        $logLevel = ($statusCode == 200) ? 'info' : 'error';
        Log::$logLevel($path . ' 響應紀錄', $responseLog);

        $warnInfo = [
            'duration' => $duration,
            'query_count' => $queryCount,
            'host' => $host,
            'path' => $path,
            'request' => $inputData,
            'request_id' => $requestId,
        ];

        // 同個 path 每30分鐘最多告警1次（使用原子性操作避免競爭條件）
        $cacheKey = CacheKeys::pathAlertCount($path);
        // Cache::add 只有在鍵不存在時才會設定，成功回傳 true
        if (!Cache::add($cacheKey, 1, CacheTtl::HALF_HOUR->value)) {
            return $response;
        }

        // 告警
        if ($duration > $queryDurationAlarmMs && !$this->isPathSkipped($path)) {
            Log::emergency("{$path} 響應過慢 {$duration} ms, {$requestId}", $warnInfo);
            Log::emergency("{$path} 查詢語法 {$requestId}", ['queries' => $sqlQueries]);
            // BotNotify::channel('warn')->send('響應過慢', $warnInfo);
        }
        // TODO 每個請求都有7次基本查詢, 看看能不能上緩存
        if ($queryCount > config('custom.alarm.query_count') && !$this->isPathSkipped($path)) {
            Log::emergency("{$path} 查詢數過高 {$queryCount}, {$requestId}", $warnInfo);
            Log::emergency("{$path} 查詢語法 {$requestId}", ['queries' => $sqlQueries]);
            // BotNotify::channel('warn')->send('查詢數過高', $warnInfo);
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

    /**
     * 檢查路徑是否在 skip 列表，支援萬用字元（使用 Str::is）
     */
    private function isPathSkipped(string $path): bool
    {
        foreach ($this->skipAlarmPaths as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 檢查路徑是否只遮硬憑證（保留其餘欄位），而非套用完整 maskData。
     */
    private function usesCredentialMaskOnly(string $path): bool
    {
        foreach ($this->credentialMaskOnlyPaths as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
