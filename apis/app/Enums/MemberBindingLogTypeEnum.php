<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberBindingLogTypeEnum: int
{
    /** 更新手機 */
    case UPDATE_PHONE = 1;
    /** 更新信箱 */
    case UPDATE_EMAIL = 2;
    /** 綁定手機 */
    case BINDING_PHONE = 3;
    /** 綁定信箱 */
    case BINDING_EMAIL = 4;
    /** 更新密碼 */
    case UPDATE_PASSWORD = 5;
    /** 更新支付密碼 */
    case UPDATE_PAYMENT_PASSWORD = 6;
    /** 填寫生日 */
    case FILL_BIRTHDAY = 7;
}
