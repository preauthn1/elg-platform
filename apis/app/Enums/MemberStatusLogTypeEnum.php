<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberStatusLogTypeEnum: int
{
    /** 登入密碼錯誤達上限鎖定 */
    case LOGIN_PASSWORD_LIMIT = 1;
    /** 驗證碼驗證失敗次數超限鎖定 */
    case VERIFICATION_CODE_LIMIT = 2;
    /** 支付密碼錯誤達上限鎖定 */
    case PAYMENT_PASSWORD_LIMIT = 3;
    /** 後台變更 */
    case BACKEND_CHANGE = 4;
    /** Google驗證錯誤達上限鎖定 工單4146 */
    case GOOGLE_AUTH_LIMIT = 5;
}
