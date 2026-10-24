<?php

declare(strict_types=1);

namespace App\Enums\NewbieChallenge;

enum NewbieChallengeBlockTypeEnum: int
{
    case IP = 1;
    case PHONE = 2;
    case ACCOUNT = 3;  // 真實姓名(first_name)
}
