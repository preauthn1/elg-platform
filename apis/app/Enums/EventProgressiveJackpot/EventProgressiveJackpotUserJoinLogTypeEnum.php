<?php

declare(strict_types=1);

namespace App\Enums\EventProgressiveJackpot;

enum EventProgressiveJackpotUserJoinLogTypeEnum: int
{
    case UNLOCK = 1; // 解鎖彩金

    case RECEIVE = 2; // 領取彩金

    case CHANGE_STATUS = 3; // 修改狀態
}
