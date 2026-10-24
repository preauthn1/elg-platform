<?php

declare(strict_types=1);

namespace App\Enums\NewbieChallenge;

enum NewbieChallengeOperatorTypeEnum: int
{
    case SYSTEM = 1;
    case MEMBER = 2;
    case AGENT = 3;
}
