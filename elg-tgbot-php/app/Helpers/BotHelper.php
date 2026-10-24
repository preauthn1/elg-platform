<?php

namespace App\Helpers;

use App\DTOs\BotInfoDTO;
use Illuminate\Support\Facades\Cache;

class BotHelper
{
    public static function getTgApiBotUrl(string $botId): string
    {
        $baseUrl = config('tgbot.url');
        $botInfo = self::getBotInfos($botId);

        return "{$baseUrl}{$botInfo->token}";
    }

    /**
     * Get bot info from cache
     */
    public static function getBotInfos(string $botId): BotInfoDTO
    {
        $cacheKey = CacheKeysHelper::getBotInfoKey($botId);
        $botInfo = Cache::get($cacheKey);

        if (empty($botInfo)) {
            throw new \Exception("Bot info not found in cache: {$cacheKey}");
        }

        return $botInfo;
    }
}
