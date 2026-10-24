<?php

namespace App\Services\ActionServices;

use Illuminate\Support\Facades\App;

class InfoService extends BaseService
{
    protected function show()
    {
        $msg = __('tgbot.please_copy_flowing_info_to_tech') . "\n";
        $msg .= 'Chat Id: ' . $this->tgWebhookData->chatId . "\n";
        $msg .= 'Username: ' . $this->tgWebhookData->username . "\n";
        $msg .= 'Locale: ' . App::getLocale() . "\n";
        $this->tgBotApiCallService->sendTextOnly($msg);
    }
}
