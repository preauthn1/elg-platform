<?php

declare(strict_types=1);

namespace App\Vendors\MotivationSW\Enums;

enum StatusCodeEnum: int
{
    case INSUFFICIENT_BALANCE = 1005;
    case PLAYER_NOT_FOUND = 1006;
    case RECORD_NOT_FOUND = 1014;
    case OPERATOR_INTERNAL_SERVER_ERROR = 1100;
}
