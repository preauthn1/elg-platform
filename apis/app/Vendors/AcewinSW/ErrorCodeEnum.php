<?php

declare(strict_types=1);

namespace App\Vendors\AcewinSW;

enum ErrorCodeEnum: int
{
    /** 成功 */
    case OK = 0;
    /** 令牌已过期 */
    case TOKEN_EXPIRED = 4;
    /** 其他错误 */
    case OTHER_ERROR = 5;
}
