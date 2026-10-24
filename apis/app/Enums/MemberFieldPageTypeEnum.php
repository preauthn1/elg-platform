<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 會員欄位設置頁面類型 (agent_member_field_settings.page_type)
 */
enum MemberFieldPageTypeEnum: int
{
    case REGISTER = 1;   // 註冊欄位設定

    case LOGIN = 2;   // 登入欄位設定

    case VERIFICATION = 3;   // 實名驗證欄位設定
}
