<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Facades\BotNotify;
use App\Helpers\Common;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * API 請求頻率監控中間件
 *
 * 監控條件：同 IP + 同路由 + 1 分鐘內 >= 50 次請求
 */
class RateLimitNotify
{
    // Redis Key 前綴
    private const string RATE_LIMIT_KEY_PREFIX = 'api:rate_limit';

    private const string ALERT_KEY_PREFIX = 'api:rate_limit_alert';

    // 時間配置（秒）
    private const int WINDOW_SECONDS = 60;           // 統計時間窗口

    private const int ALERT_COOLDOWN_SECONDS = 60;   // 告警冷卻時間

    // 預設閾值
    private const int DEFAULT_THRESHOLD = 50;

    public function handle(Request $request, Closure $next): Response
    {
        $appName = config('app.name');
        if ($appName !== 'Frontend_API') {
            return $next($request);
        }

        $ip = Common::getIp();
        if (empty($ip)) {
            Log::warning('Rate limit: Unable to get client IP');

            return $next($request);
        }

        // 白名單直接放行
        if ($this->isWhitelisted($ip)) {
            return $next($request);
        }

        // 組合 Redis Key
        $route = $request->path();
        $minute = now()->format('YmdHi');
        $rateLimitKey = $this->buildRateLimitKey($ip, $route, $minute);

        // 請求計數
        try {
            $count = $this->incrementRequestCount($rateLimitKey);
        } catch (\Exception $e) {
            Log::error('Rate limit: Redis connection failed', [
                'ip' => $ip,
                'route' => $route,
                'error' => $e->getMessage(),
            ]);

            return $next($request);
        }

        // 檢查是否超過閾值
        if ($count >= $this->getThreshold()) {
            $alertKey = $this->buildAlertKey($ip, $route, $minute);
            $this->sendAlertIfNeeded($request, $ip, $route, $count, $alertKey);

            // 可選：啟用以阻擋超過閾值的請求
            // abort(429, 'Too Many Requests');
        }

        return $next($request);
    }

    /**
     * 建立 Redis Key
     */
    protected function buildRateLimitKey(string $ip, string $route, string $minute): string
    {
        return $this->buildKey(self::RATE_LIMIT_KEY_PREFIX, $ip, $route, $minute);
    }

    protected function buildAlertKey(string $ip, string $route, string $minute): string
    {
        return $this->buildKey(self::ALERT_KEY_PREFIX, $ip, $route, $minute);
    }

    private function buildKey(string $prefix, string $ip, string $route, string $minute): string
    {
        $sanitizedRoute = str_replace('/', ':', trim($route, '/'));

        return sprintf('%s:%s:%s:%s', $prefix, $ip, $sanitizedRoute, $minute);
    }

    /**
     * 檢查 IP 是否在白名單
     */
    protected function isWhitelisted(string $ip): bool
    {
        return in_array($ip, $this->getWhitelist(), true);
    }

    /**
     * 取得白名單 IP 列表
     */
    protected function getWhitelist(): array
    {
        return array_filter([
            '0.0.0.0',
            '::1',
            config('custom.office.ip'),
            ...config('custom.rate_limit.whitelist', []),
        ]);
    }

    /**
     * 取得請求閾值
     */
    protected function getThreshold(): int
    {
        return (int) config('custom.rate_limit.threshold', self::DEFAULT_THRESHOLD);
    }

    /**
     * 取得 Redis 連線
     */
    protected function getRedisConnection(): Connection
    {
        $connectionName = config('custom.rate_limit.redis_connection', 'cache');

        return Redis::connection($connectionName);
    }

    /**
     * 增加請求計數（原子操作 + 自動過期）
     */
    protected function incrementRequestCount(string $key): int
    {
        $redis = $this->getRedisConnection();

        /** @phpstan-ignore arguments.count */
        $results = $redis->pipeline(function ($pipe) use ($key) {
            $pipe->incr($key);
            $pipe->ttl($key);
        });

        $count = (int) $results[0];
        $ttl = (int) $results[1];

        // TTL 為 -1 表示 key 存在但無過期時間，需設置過期
        if ($ttl === -1) {
            $redis->expire($key, self::WINDOW_SECONDS);
        }

        return $count;
    }

    /**
     * 發送告警通知（冷卻時間內只通知一次）
     */
    protected function sendAlertIfNeeded(Request $request, string $ip, string $route, int $count, string $alertKey): void
    {
        try {
            $redis = $this->getRedisConnection();

            // 使用 SET NX EX 原子操作確保冷卻時間內只通知一次
            $alerted = $redis->set($alertKey, 1, 'EX', self::ALERT_COOLDOWN_SECONDS, 'NX');

            if (!$alerted) {
                return;
            }

            $alertMessage = $this->buildAlertMessage($request, $ip, $route, $count);
            $alertContext = $this->buildAlertContext($request, $ip, $route, $count);

            Log::emergency('Rate limit threshold exceeded', $alertContext);
            BotNotify::channel('warn')->send($alertMessage);
        } catch (\Exception $e) {
            Log::error('Rate limit: Alert notification failed', [
                'ip' => $ip,
                'route' => $route,
                'count' => $count,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 建立告警訊息
     */
    protected function buildAlertMessage(Request $request, string $ip, string $route, int $count): string
    {
        return sprintf(
            "請求頻率告警\n\n" .
            "來源 IP: %s\n" .
            "請求路由: %s\n" .
            "請求次數: 每分鐘 %d 次\n" .
            'User-Agent: %s',
            $ip,
            $route,
            $count,
            $request->userAgent() ?: 'Unknown'
        );
    }

    /**
     * 建立告警日誌上下文
     */
    protected function buildAlertContext(Request $request, string $ip, string $route, int $count): array
    {
        return [
            'ip' => $ip,
            'route' => $route,
            'count' => $count,
            'threshold' => $this->getThreshold(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'path' => $request->path(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('referer'),
            'timestamp' => now()->toDateTimeString(),
        ];
    }
}
