<?php

namespace App\Helpers;

use App\Enums\ReplyMessageStatusEnum;

class ReplyMessageHelper
{
    public static function register(string $messageId, ReplyMessageStatusEnum $status)
    {
        $cacheData = [
            'status' => $status->value,
        ];
        CacheHelper::putCacheAndLog(CacheKeysHelper::getRepliedMessageKey($messageId), $cacheData);
    }
}
