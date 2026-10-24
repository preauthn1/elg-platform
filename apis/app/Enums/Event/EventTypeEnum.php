<?php

declare(strict_types=1);

namespace App\Enums\Event;

enum EventTypeEnum: int
{
    case REGULAR = 1; // 一般活動
    case VIP = 2; // VIP活動
    case JACKPOT = 3; // Jackpot活動
    case RANKING = 4; // 積分排行
}
