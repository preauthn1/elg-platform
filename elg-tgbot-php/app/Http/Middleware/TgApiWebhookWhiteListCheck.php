<?php

namespace App\Http\Middleware;

use App\Helpers\CacheKeysHelper;
use App\Helpers\CommonHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TgApiWebhookWhiteListCheck
{
    private array $whitelist = [];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = CommonHelper::getRealIp();

        $whitelistString = Cache::get(CacheKeysHelper::getTgApiWhiteListKey());
        $this->whitelist = explode("\n", trim($whitelistString, "\n")); // prevent empty string

        if (empty($ip)) {
            Log::warning('Request IP is empty');

            return response()->json(['error' => 'Unauthorized'], 403);
        } elseif (!$this->isIPWhitelisted($ip)) {
            // 记录日志或返回 403 错误
            Log::warning("Unauthorized access attempt from IP: $ip");

            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $next($request);
    }

    protected function isIPWhitelisted(string $ip): bool
    {
        foreach ($this->whitelist as $cidr) {
            $cidr = trim($cidr);
            if ($this->ipInRange($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    protected function ipInRange(string $ip, string $cidr): bool
    {
        $cidrSettings = explode('/', $cidr);
        $subnet = $cidrSettings[0] ?? '';
        $mask = (int) ($cidrSettings[1] ?? 32);

        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->cidrMatchIPv4($ip, $subnet, $mask);
        } elseif (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->cidrMatchIPv6($ip, $subnet, $mask);
        }

        return false;
    }

    protected function cidrMatchIPv4(string $ip, string $subnet, int $mask): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        /**
         * if mask = 3, max = 6.
         * first step (1 << (6 - $mask)):  000000 => 001000
         * second step ((first step) - 1): 001000 => 000111
         * third step ~((second step)):    000111 => 111000 (maskLong)
         */
        $maskLong = ~((1 << (32 - $mask)) - 1);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    protected function cidrMatchIPv6($ip, $subnet, $mask): bool
    {
        $ipBin = unpack('H*', inet_pton($ip))[1];
        $subnetBin = unpack('H*', inet_pton($subnet))[1];

        $maskBin = str_repeat('f', intval($mask / 4)) . str_repeat('0', 32 - intval($mask / 4));
        $maskBin = hex2bin($maskBin);

        return substr($ipBin, 0, strlen($maskBin)) === substr($subnetBin, 0, strlen($maskBin));
    }
}
