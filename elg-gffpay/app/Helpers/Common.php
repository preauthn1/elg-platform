<?php

namespace App\Helpers;

use App\Enums\General\CacheTtl;
use App\Helpers\Traits\CacheTrait;
use App\Helpers\Traits\MathTrait;
use App\Helpers\Traits\MonitorTrait;
use App\Models\Vendor;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class Common
{
    use CacheTrait;
    use MathTrait;
    use MonitorTrait;

    /**
     * 是否為空字串或NULL
     *
     * @deprecated
     */
    public static function isNullOrEmptyString($str): bool
    {
        return empty($str) || trim($str) === '';
    }

    /**
     * @deprecated 額外的資訊已使用 Log::withContext 放在上下文
     */
    public static function log($content, $action)
    {
        // 刪除PASSWORD相關的KEY
        try {
            if (is_array($content)) {
                $content = collect($content)->map(function ($value, $key) {
                    if (is_array($value) && isset($value['password'])) {
                        $value['password'] = '******';
                    }

                    return $value;
                })->all();
            }
            $ip = Common::getIp();
            $context = [
                'content' => $content,
                'action' => $action,
                'ip' => $ip,
            ];
            $action = is_array($context['action']) ? json_encode($action) : $action;

            $message = sprintf('access %s from %s',
                $action,
                $context['ip']);
            Log::info($message, $context);
        } catch (Exception $e) {
            Log::error($e->getMessage(), $context ?? []);
        }
    }

    /**
     * 過濾敏感資訊
     *
     * 根據指定的規則過濾資料中的敏感資訊，例如密碼、Token、手機號碼等。
     *
     * @param  mixed  $data  要過濾的資料，可以是陣列或單一值
     * @param  string|null  $ruleKey  指定的過濾規則鍵，若為單一值時使用
     * @return mixed 過濾後的資料
     */
    public static function maskData(mixed $data, ?string $ruleKey = null): mixed
    {
        $rules = [
            'password' => fn ($value) => '******',
            'token' => fn ($value) => str_repeat('*', 7) . substr($value, 5),
            'mobile' => fn ($value) => is_string($value) && !Str::startsWith($value, 'http') ? substr($value, 0, 3) . str_repeat('*', max(0, strlen($value) - 7)) . substr($value, -4) : $value,
            'email' => fn ($value) => is_string($value) ? substr($value, 0, 1) . '*' . substr($value, -1) : $value,
        ];

        // 若資料為單一值且指定了規則鍵，直接套用規則
        if (!is_array($data) && $ruleKey) {
            return $rules[$ruleKey]($data);
        }

        if (is_array($data)) {
            // 遍歷資料陣列，根據鍵名套用對應的過濾規則
            foreach ($data as $key => $value) {
                if (isset($rules[$key])) {
                    $data[$key] = $rules[$key]($value);
                }
            }
        }

        return $data;
    }

    public static function getIp(): string
    {
        $headers = [
            'HTTP_ALI_CDN_REAL_IP',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                return self::extractFirstIp($_SERVER[$header]);
            }
        }

        $clientIp = request()->header('V-HTTP-CLIENT-IP');
        if (!empty($clientIp)) {
            return self::extractFirstIp($clientIp);
        }

        return self::extractFirstIp(request()->server('REMOTE_ADDR')) ?? '0.0.0.0';
    }

    public static function extractFirstIp(string $ips): string
    {
        $ips = explode(',', $ips);
        // 去除多餘空格
        $ips = array_map('trim', $ips);
        // 排除空字串
        $ips = array_filter($ips, function ($ip) {
            return !empty($ip);
        });

        return $ips[0];
    }

    public static function verifyIPWhitelistByVendorId(int $vendorId): bool
    {
        $vendor = Vendor::find($vendorId);

        if (empty($vendor->whitelist)) {
            return true;
        }

        return self::verifyIPWhitelist($vendor->whitelist);
    }

    public static function verifyIPWhitelist(string $whitelist): bool
    {
        if (config('app.env') != 'production') {
            return true;
        }

        /** IP 白名單驗證 **/
        if (empty($whitelist)) {
            return true;
        }

        // 內定 IP 白名單
        $whitelistIPs = array_map('trim', explode(',', $whitelist));

        // 請求的 IP
        $requestIPs = array_map('trim', explode(',', self::getIp()));

        // 檢查是否有任一 IP 在白名單中
        $matchingIPs = array_intersect($requestIPs, $whitelistIPs);
        if (empty($matchingIPs)) {
            return false;
        }

        return true;
    }

    /**
     * 隱藏字串部分內容, 預設隱藏中間60%, 用*代替
     */
    public static function maskString(string $value): string
    {
        $length = strlen($value);
        $maskLength = floor($length * 0.6);
        $leftLength = floor(($length - $maskLength) / 2);
        $rightLength = ceil(($length - $maskLength) / 2);

        return substr($value, 0, (int) $leftLength)
            . str_repeat('*', (int) $maskLength)
            . substr($value, $length - (int) $rightLength);
    }

    /**
     * 取得API呼叫次數, 包含此次
     *
     * @param  string  $apiName  api key
     * @param  int  $seconds  幾秒內的呼叫次數
     * @param  int  $memberId  會員ID
     */
    public static function getApiCallCount(string $apiName, int $seconds, int $memberId = 0): int
    {
        $key = "api_call_count:{$apiName}:{$memberId}";
        $timestampMs = now()->getTimestampMs();
        Redis::zAdd($key, $timestampMs, Str::uuid());
        Redis::expire($key, CacheTtl::ONE_DAY->value);

        return Redis::zCount($key, $timestampMs - $seconds * 1000, $timestampMs);
    }

    public static function clearApiCallCount(string $apiName, int $memberId = 0): void
    {
        Redis::del("api_call_count:{$apiName}:{$memberId}");
    }

    public static function flattenHeaders(array $headers): array
    {
        // 只取第一個值
        $flattened = array_map(function ($values) {
            return $values[0] ?? null;
        }, $headers);

        return $flattened;
    }
}
