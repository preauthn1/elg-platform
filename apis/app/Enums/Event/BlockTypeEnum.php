<?php

declare(strict_types=1);

namespace App\Enums\Event;

enum BlockTypeEnum: int
{
    case ACCOUNT_BLOCK = 1;
    case IP_BLOCK = 2;
    case PHONE_BLOCK = 3;
    case TGID_BLOCK = 6;
    case GLOBAL_ACCOUNT_BLOCK = 7;
    case GLOBAL_IP_BLOCK = 8;
    case GLOBAL_PHONE_BLOCK = 9;
}
