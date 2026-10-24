<?php

namespace App\Helpers;

use App\DTOs\TgWebhookDataDTO;
use App\Models\ChatLanguageSetting;
use App\Singletons\PlatformApiCallService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LanguageHelper
{
    public static function setLocale(string $language = ''): void
    {
        $cacheKey = CacheKeysHelper::getUserLanguageKey();
        $tgWebhookDataDTO = app(TgWebhookDataDTO::class);

        if (!empty($language)) {
            ChatLanguageSetting::updateOrCreate(
                ['bot_id' => $tgWebhookDataDTO->botId, 'chat_id' => $tgWebhookDataDTO->chatId],
                ['language' => $language]
            );
            CacheHelper::putCacheAndLog($cacheKey, $language);
            App::setLocale($language);

            return;
        }

        // Use Cache
        $cacheLanguage = Cache::get($cacheKey);
        $dbChatLanguage = '';
        $dbDefaultLanguage = '';
        if (empty($cacheLanguage)) {
            $chatLanguageSetting = ChatLanguageSetting::where('bot_id', $tgWebhookDataDTO->botId)
                ->where('chat_id', $tgWebhookDataDTO->chatId)
                ->first();

            if ($chatLanguageSetting) {
                $dbChatLanguage = $chatLanguageSetting->language;
                $language = $dbChatLanguage;
            } else {
                // 取得 DB 配置的預設語言
                $platformApiCallService = app(PlatformApiCallService::class);
                $infoData = $platformApiCallService->getHomepageInfo();
                $dbDefaultLanguage = $infoData->defaultLanguage;
                $language = $dbDefaultLanguage;

                // 寫入 DB
                ChatLanguageSetting::updateOrCreate(
                    ['bot_id' => $tgWebhookDataDTO->botId, 'chat_id' => $tgWebhookDataDTO->chatId],
                    ['language' => $dbDefaultLanguage]
                );
            }

            CacheHelper::putCacheAndLog($cacheKey, $language);
        } else {
            $language = $cacheLanguage;
        }

        $logData = [
            'bot_id' => $tgWebhookDataDTO->botId,
            'chat_id' => $tgWebhookDataDTO->chatId,
            'messageId' => $tgWebhookDataDTO->messageId,
            'cacheLanguage' => $cacheLanguage,
            'dbChatLanguage' => $dbChatLanguage,
            'dbDefaultLanguage' => $dbDefaultLanguage,
            'configLanguage' => config('app.locale'),
            'language' => $language,
        ];

        Log::info('tgbot_language', $logData);
        App::setLocale($language);
    }
}
