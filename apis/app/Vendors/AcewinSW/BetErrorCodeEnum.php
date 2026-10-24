<?php

declare(strict_types=1);

namespace App\Vendors\AcewinSW;

enum BetErrorCodeEnum: int
{
    /** 成功 */
    case OK = 0;
    /** 重复交易 */
    case REPEAT_TRANSACTION = 1;
    /** 余额不足 */
    case INSUFFICIENT_BALANCE = 2;
    /** 參數錯誤 */
    case INVALID_PARAMETER = 3;
    /** 令牌已过期 */
    case TOKEN_EXPIRED = 4;
    /** 其他错误 */
    case OTHER_ERROR = 5;
}
