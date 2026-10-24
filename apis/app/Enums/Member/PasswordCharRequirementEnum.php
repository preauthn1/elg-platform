<?php

declare(strict_types=1);

namespace App\Enums\Member;

/**
 * 密碼字元類型的要求（password 欄位 extra_config.rules 的值）：必須包含／可包含／不可包含。
 */
enum PasswordCharRequirementEnum: string
{
    case REQUIRED = 'required';   // 必須包含

    case OPTIONAL = 'optional';   // 可包含（有沒有都可以）

    case FORBIDDEN = 'forbidden';   // 不可包含

    /**
     * 解析設定值；舊版勾選制存 bool：有勾＝必須包含、沒勾＝可包含（沿用勾選制「沒勾就不檢查」的行為）。
     */
    public static function fromConfig(mixed $value): self
    {
        if (is_bool($value)) {
            return $value ? self::REQUIRED : self::OPTIONAL;
        }

        return is_string($value) ? (self::tryFrom($value) ?? self::OPTIONAL) : self::OPTIONAL;
    }
}
