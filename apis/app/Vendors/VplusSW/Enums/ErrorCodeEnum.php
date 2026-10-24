<?php

declare(strict_types=1);

namespace App\Vendors\VplusSW\Enums;

enum ErrorCodeEnum: int
{
    case SUCCESS = 0;
    case USER_NOT_FOUND = 1;
    case SYSTEM_ERROR = 2;
    case BET_IS_NOT_ALLOWED = 3;
    case VERIFICATION_FAILED = 4;
    case PLAYER_FROZEN = 5;
    case REQUEST_PARAMETER_ERROR = 6;
    case GAME_NOT_FOUND_OR_DISABLED = 7;
    case BET_LIMIT_REACHED = 8;
    case REPEAT_REQUEST = 9;
    case BET_ALREADY_CANCELED = 10;
    case BET_ALREADY_SETTLED = 11;
    case OTHER = 12;
}
