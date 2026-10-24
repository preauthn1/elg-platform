<?php

namespace App\Services\ActionServices;

use App\DTOs\TgWebhookDataDTO;
use App\Singletons\TgBotApiCallService;
use Illuminate\Support\Facades\App;

/**
 * @deprecated 改用 ErrorService 跟 InfoService
 */
class NormalMessageService
{
    public static function sendMessage_DebugInfo()
    {
        $tgWebhookData = app(TgWebhookDataDTO::class);
        $msg = __('tgbot.please_copy_flowing_info_to_tech') . "\n";
        $msg .= $tgWebhookData->chatId . '  |  ' . $tgWebhookData->username . "\n\n";
        $msg .= 'Local: ' . App::getLocale() . "\n";
        self::sendMessage($msg);
    }

    public static function sendMessage(string $content)
    {
        app(TgBotApiCallService::class)->sendTextOnly($content);
    }
}
