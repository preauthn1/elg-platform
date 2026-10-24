<?php

declare(strict_types=1);

namespace App\Enums\NewbieChallenge;

enum NewbieChallengeBlockJoinStatusEnum: int
{
    case BLOCKED = 1;  // 因重複IP/手機/真實姓名被擋下，等待後台審核
    case ALLOW_JOIN = 2;  // 後台已放行，等待玩家重新嘗試 join
    case JOINED = 3;  // 玩家已用此放行完成參加
}
