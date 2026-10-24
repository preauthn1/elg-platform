<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberTypeEnum: int
{
    /** 一般會員、份桶會員 */
    case NORMAL = 0;
    /** 佔成會員 */
    case SHARE = 2;
}
