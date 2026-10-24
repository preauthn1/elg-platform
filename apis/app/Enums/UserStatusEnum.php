<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatusEnum: int
{
    /** 未認證 */
    case UNVERIFIED = 0;
    /** 正常 */
    case NORMAL = 1;
    /** 鎖定 */
    case LOCKED = 2;
}
