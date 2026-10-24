<?php

declare(strict_types=1);

namespace App\Vendors\BeSoftSW\Enums;

enum BeSoftStatusEnum: int
{
    case SUCCESS = 1;
    case SYSTEM_ERROR = 1003;
    case USER_NOT_FOUND = 1032;
}
