<?php

declare(strict_types=1);

namespace App\Vendors\TTHSW\Helpers;

/**
 * @knowledge rules/vendor-tthsw
 */
class SignatureHelper
{
    /** C# HttpUtility.UrlEncode 不編碼的字元 */
    private const string URL_SAFE_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.!*()';

    /**
     * Convert array to sorted query string
     *
     * @param  array  $data  Data to convert
     */
    public static function toSortedQueryString(array $data): string
    {
        $parameters = [];
        self::flatten($data, '', $parameters);

        $keys = array_keys($parameters);
        sort($keys);

        $pairs = [];
        foreach ($keys as $key) {
            $pairs[] = self::dotNetUrlEncode((string) $key) . '=' . self::dotNetUrlEncode($parameters[$key]);
        }

        return implode('&', $pairs);
    }

    /**
     * Flatten nested payload into key => string value pairs
     */
    private static function flatten(mixed $value, string $prefix, array &$parameters): void
    {
        if (is_bool($value)) {
            $parameters[$prefix] = $value ? 'true' : 'false';

            return;
        }

        if (is_scalar($value)) {
            $stringValue = (string) $value;
            if ($stringValue !== '') {
                $parameters[$prefix] = $stringValue;
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        if (array_is_list($value)) {
            $index = 0;
            foreach ($value as $item) {
                if (self::isEmptyValue($item)) {
                    continue;
                }
                self::flatten($item, "{$prefix}[{$index}]", $parameters);
                $index++;
            }

            return;
        }

        foreach ($value as $key => $item) {
            if (self::isEmptyValue($item)) {
                continue;
            }
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            self::flatten($item, $fullKey, $parameters);
        }
    }

    /**
     * 文檔規範需排除的空值：null、空字串、空陣列
     */
    private static function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * 依三方驗簽端 C# HttpUtility.UrlEncode 的規則編碼
     *
     * 不編碼 URL_SAFE_CHARS，空白編為 +，其餘位元組編為小寫 %xx。
     * PHP 的 urlencode 會多編 !*()、rawurlencode 會把空白編成 %20，兩者都無法直接對應。
     */
    private static function dotNetUrlEncode(string $value): string
    {
        $encoded = '';

        foreach (str_split($value) as $char) {
            if (str_contains(self::URL_SAFE_CHARS, $char)) {
                $encoded .= $char;

                continue;
            }

            $encoded .= $char === ' ' ? '+' : '%' . strtolower(bin2hex($char));
        }

        return $encoded;
    }

    /**
     * Generate signature
     *
     * @param  array  $data  Data to sign
     * @param  string  $secretKey  Secret key
     * @param  int  $timestamp  Timestamp in milliseconds
     * @return string Base64 encoded signature
     */
    public static function generateSignature(array $data, string $secretKey, int $timestamp): string
    {
        $queryString = self::toSortedQueryString($data);
        $signatureString = $queryString . '|' . $timestamp;
        $hash = hash_hmac('sha256', $signatureString, $secretKey, true);

        return base64_encode($hash);
    }

    /**
     * Verify signature
     *
     * @param  array  $data  Data to verify
     * @param  string  $secretKey  Secret key
     * @param  string  $signature  Received signature
     * @param  int  $timestamp  Received timestamp
     */
    public static function verifySignature(array $data, string $secretKey, string $signature, int $timestamp): bool
    {
        $expectedSignature = self::generateSignature($data, $secretKey, $timestamp);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Verify timestamp is within valid range (prevent replay attacks)
     *
     * @param  int  $timestamp  Millisecond timestamp
     * @param  int  $toleranceSeconds  Tolerance in seconds (default 300 seconds = 5 minutes)
     */
    public static function verifyTimestamp(int $timestamp, int $toleranceSeconds = 300): bool
    {
        $currentTimestamp = (int) (microtime(true) * 1000);
        $diff = abs($currentTimestamp - $timestamp);

        // Convert to seconds for comparison
        return ($diff / 1000) <= $toleranceSeconds;
    }
}
