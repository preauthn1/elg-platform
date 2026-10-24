<?php

declare(strict_types=1);

namespace App\Enums\BotNotify;

/**
 * BotNotify 告警分類：封裝「例外類型」到「中文訊息標題」的對映，
 * 取代 bootstrap callback 內的魔術字串 title 組裝。
 */
enum AlertCategory: string
{
    case HTTP_TIMEOUT = 'http_timeout';
    case HTTP_ERROR = 'http_error';
    case SQL_ERROR = 'sql_error';
    case LOCK_TIMEOUT = 'lock_timeout';
    case ENUM_ERROR = 'enum_error';
    case VENDOR_SETUP = 'vendor_setup';
    case VENDOR_NOT_FOUND = 'vendor_not_found';
    case VENDOR_ACCOUNT = 'vendor_account';
    case INVALID_ARGUMENT = 'invalid_argument';
    case SMS_GATEWAY = 'sms_gateway';
    case THROWABLE = 'throwable';

    /**
     * 訊息標題（中文）。HTTP_ERROR 依 status code 解析錯誤類型並帶上狀態碼。
     */
    public function label(?int $status = null): string
    {
        return match ($this) {
            self::HTTP_TIMEOUT => 'HTTP 連線超時',
            self::HTTP_ERROR => sprintf('HTTP 請求錯誤 - %s (HTTP %d)', self::httpErrorType($status ?? 0), $status ?? 0),
            self::SQL_ERROR => 'SQL 查詢錯誤',
            self::LOCK_TIMEOUT => '緩存鎖超時',
            self::ENUM_ERROR => 'Enum 錯誤',
            self::VENDOR_SETUP => 'Vendor 配置錯誤',
            self::VENDOR_NOT_FOUND => 'Vendor 不存在',
            self::VENDOR_ACCOUNT => 'Vendor 帳號錯誤',
            self::INVALID_ARGUMENT => '參數錯誤',
            self::SMS_GATEWAY => 'sms發送失敗',
            self::THROWABLE => 'Throwable',
        };
    }

    /**
     * HTTP 錯誤類型對映（中文）。
     */
    public static function httpErrorType(int $status): string
    {
        return match (true) {
            $status === 429 => '速率限制',
            $status === 401, $status === 403 => '認證失敗',
            $status === 404 => '端點不存在',
            $status === 502, $status === 503, $status === 504 => '服務不可用',
            $status >= 500 => '服務器錯誤',
            $status >= 400 => '請求錯誤',
            default => '請求失敗',
        };
    }
}
