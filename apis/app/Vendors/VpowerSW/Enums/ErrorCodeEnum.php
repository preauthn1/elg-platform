<?php

declare(strict_types=1);

namespace App\Vendors\VpowerSW\Enums;

enum ErrorCodeEnum: int
{
    case SUCCESS = 0;
    case USER_NOT_FOUND = 1;
    case OTHER_EXCEPTIONS = 2;
}
