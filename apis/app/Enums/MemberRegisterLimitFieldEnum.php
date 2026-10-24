<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 註冊限制欄位鍵 (agent_register_limits.field_key)
 * 限制「同一個值」可被重複註冊的次數（反多帳號/詐欺）
 */
enum MemberRegisterLimitFieldEnum: string
{
    case EMAIL = 'email';   // 電子郵件

    case PHONE = 'phone';   // 電話

    case IP_ADDRESS = 'ip_address';   // IP 位址

    case NAME = 'name';   // 真實姓名

    /** 顯示名稱(錯誤訊息用,避免把 field_key 原文丟給用戶) */
    public function label(): string
    {
        return match ($this) {
            self::EMAIL => '電子郵件',
            self::PHONE => '電話',
            self::IP_ADDRESS => 'IP 位址',
            self::NAME => '真實姓名',
        };
    }
}
