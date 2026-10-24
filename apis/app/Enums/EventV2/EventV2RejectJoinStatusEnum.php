<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

enum EventV2RejectJoinStatusEnum: int
{
    case REJECT = 1; // 拒絕參加

    case ALLOW_JOIN = 2; // 同意參加

    case JOIN = 3; // 已參加
}
