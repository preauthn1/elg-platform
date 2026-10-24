<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Facades\BotNotify;
use App\Helpers\Common;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RateLimitNotify
{
    public function handle(Request $request, Closure $next)
    {
        $ip = Common::getIp();
        $minute = now()->format('YmdHi');
        $key = "api:rate_limit:{$ip}:{$minute}";
        $alertKey = "api:rate_limit_alert:{$ip}:{$minute}";

        $whitelist = [
            '0.0.0.0',
            '::1',
            config('custom.office.ip'),
            // 其他可信任 IP 可在此加入
        ];
        if (in_array($ip, $whitelist, true)) {
            return $next($request);
        }

        $count = Redis::incr($key);
        if ($count === 1) {
            Redis::expire($key, 60);
        }

        if ($count > 100) {
            if (Redis::setnx($alertKey, 1)) {
                Redis::expire($alertKey, 60);
                // 通知
                $log = "IP {$ip} 每分鐘請求已達 {$count} 次";
                Log::emergency($log);
                BotNotify::channel('warn')->send($log);
            }

            // 中斷請求並回傳錯誤
            // abort(429, 'Too Many Requests');
        }

        return $next($request);
    }
}
