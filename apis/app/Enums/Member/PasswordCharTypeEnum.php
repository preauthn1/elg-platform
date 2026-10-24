<?php

declare(strict_types=1);

namespace App\Enums\Member;

/**
 * 密碼字元類型（password 欄位 extra_config.rules 的鍵）。英文字母不分大小寫。
 */
enum PasswordCharTypeEnum: string
{
    case ALPHABET = 'alphabet';   // 英文字母

    case NUMBER = 'number';   // 數字

    case SYMBOL = 'symbol';   // 符號

    /**
     * 判斷該類型字元的 pattern；輸入已先確認全為半形，非英數即符號。
     */
    public function pattern(): string
    {
        return match ($this) {
            self::ALPHABET => '/[A-Za-z]/',
            self::NUMBER => '/[0-9]/',
            self::SYMBOL => '/[^A-Za-z0-9]/',
        };
    }

    public function label(): string
    {
        return __('error_message.password_char_type.' . $this->value);
    }
}
