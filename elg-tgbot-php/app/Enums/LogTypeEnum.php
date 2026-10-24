<?php

namespace App\Enums;

enum LogTypeEnum: string
{
    case CACHE = 'cache';
    case TG_WEBHOOK = 'tgbot_webhook';
    case TG_API_CALL = 'tgbot_tg_api_call';
    case PLATFORM_API = 'platform_api';

}
