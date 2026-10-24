<?php

namespace App\Helpers;

use App\DTOs\TgWebhookDataDTO;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class LogHelper
{
    public static function cacheChannel(
        string $botId = '',
        string $chatId = '',
        bool $onlyPath = false
    ): LoggerInterface|string {
        $botId = $botId ?: app(TgWebhookDataDTO::class)->botId;
        $chatId = $chatId ?: app(TgWebhookDataDTO::class)->chatId;
        $path = storage_path("logs/{$botId}/{$chatId}/cache_log.log");

        if ($onlyPath) {
            return $path;
        }

        return self::buildLoggerInterfacce($path);
    }

    public static function tgWebhookReqChannel(
        string $botId = '',
        string $chatId = '',
        bool $onlyPath = false
    ): LoggerInterface|string {
        $botId = $botId ?: app(TgWebhookDataDTO::class)->botId;
        $chatId = $chatId ?: app(TgWebhookDataDTO::class)->chatId;
        $path = storage_path("logs/{$botId}/{$chatId}/tgbot_webhook_log.log");

        if ($onlyPath) {
            return $path;
        }

        return self::buildLoggerInterfacce($path);
    }

    public static function tgApiCallChannel(
        string $botId = '',
        string $chatId = '',
        bool $onlyPath = false
    ): LoggerInterface|string {
        $botId = $botId ?: app(TgWebhookDataDTO::class)->botId;
        $chatId = $chatId ?: app(TgWebhookDataDTO::class)->chatId;
        $path = storage_path("logs/{$botId}/{$chatId}/tgbot_tg_api_call.log");

        if ($onlyPath) {
            return $path;
        }

        return self::buildLoggerInterfacce($path);
    }

    public static function platformApiChannel(
        string $botId = '',
        string $chatId = '',
        bool $onlyPath = false
    ): LoggerInterface|string {
        $botId = $botId ?: app(TgWebhookDataDTO::class)->botId;
        $chatId = $chatId ?: app(TgWebhookDataDTO::class)->chatId;
        $path = storage_path("logs/{$botId}/{$chatId}/platform_api_call.log");

        if ($onlyPath) {
            return $path;
        }

        return self::buildLoggerInterfacce($path);
    }

    private static function buildLoggerInterfacce(string $path): LoggerInterface
    {
        return Log::build([
            'driver' => 'single',
            'path' => $path,
            'tap' => [\App\Loggers\ApplogFormatter::class],
        ]);
    }
}
