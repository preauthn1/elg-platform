<?php

declare(strict_types=1);

namespace App\Vendors\NextSpin;

enum ErrorCodeEnum: int
{
    /** 成功 */
    case OK = 0;
    /** 帳號不存在 */
    case ACCT_NOT_FOUND = 50100;
    /** 系統錯誤 */
    case SYSTEM_ERROR = 1;
    /** 請求被拒絕 */
    case REQUEST_FORBIDDEN = 104;
    /** Token 驗證失敗 */
    case TOKEN_VALIDATION_FAILED = 50104;
    /** 商戶不存在 */
    case MERCHANT_NOT_FOUND = 10113;
}
