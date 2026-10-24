<?php

declare(strict_types=1);

namespace App\Vendors\OnebxSW\Enums;

enum ErrorCodeEnum: int
{
    /**
     * 系統錯誤, with http status code 500
     */
    case SYSTEM_ERROR = 1;

    /**
     * 無效的簽名, with http status code 401
     */
    case INVALID_SIGNATURE = 2;

    /**
     * 錢包餘額不足, with http status code 400
     */
    case INSUFFICIENT_BALANCE = 3;

    /**
     * 用戶不存在, with http status code 401
     */
    case USER_NOT_FOUND = 4;

    /**
     * 用戶未授權投注, with http status code 401
     */
    case USER_NOT_AUTHORIZED_TO_BET = 5;

    /**
     * 超過每日贏錢上限, with http status code 400
     */
    case EXCEED_DAILY_WIN_LIMIT = 6;
}
