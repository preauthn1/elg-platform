<?php

namespace App\Helpers;

use App\Enums\LogTypeEnum;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheHelper
{
    public static function putCacheAndLog(string $key, mixed $value, int $seconds = 600): void
    {
        Cache::put($key, $value, $seconds);

        if (config('app.debug')) {
            Log::info("Put Cache. key: {$key}", [
                'data' => $value,
                'extra' => ['type' => LogTypeEnum::CACHE->value],
            ]);
        }
    }

    public static function getUserLanguageKey(): string
    {
        $tgWebhookData = app()->make(\App\DTOs\TgWebhookDataDTO::class);

        return "user_language_{$tgWebhookData->chatId}";
    }

    public static function getRepliedMessageKey(string $messageId): string
    {
        $tgWebhookData = app()->make(\App\DTOs\TgWebhookDataDTO::class);

        return "replied_message_{$tgWebhookData->botId}_{$tgWebhookData->chatId}_{$messageId}";
    }

    public static function getRegistrationKey(): string
    {
        $tgWebhookData = app()->make(\App\DTOs\TgWebhookDataDTO::class);

        return "registration_{$tgWebhookData->botId}_{$tgWebhookData->chatId}";
    }

    public static function getDepositKey(): string
    {
        $tgWebhookData = app()->make(\App\DTOs\TgWebhookDataDTO::class);

        return "deposit_{$tgWebhookData->botId}_{$tgWebhookData->chatId}";
    }

    public static function getCocurrentLockerKey(string $botId, string $chatId): string
    {
        return "concurrent_locker_{$botId}_{$chatId}";
    }

    public static function getTgApiWhiteListKey(): string
    {
        return 'tg_api_white_list';
    }
}
