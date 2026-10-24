<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 風控IP限制的阻擋項目（風控管理 > IP限制，4096）。
 *
 * 一筆規則可同時勾選多個項目（後台多選），DB 以三個獨立旗標欄位存放
 * （與風控會員限制的 is_disabled / withdrawal_locked 同一套寫法）。
 *
 * 三種項目共用同一組錯誤碼 ERROR_CODE：它同時是回應 body 的 code 與訊息文案
 * 括號內的碼，讓會員前台直接顯示、客服憑碼對照（例：登入失敗(900001)）。
 * 要分辨是哪一種阻擋看文案即可，不需要三組碼。
 */
enum IpRestrictionBlockItemEnum: string
{
    /** 風控IP限制阻擋（不分項目）的錯誤碼 */
    public const ERROR_CODE = 900001;

    case REGISTER = 'register';

    case LOGIN = 'login';

    case SMS = 'sms';

    /** 對應 ip_restrictions 的旗標欄位 */
    public function column(): string
    {
        return match ($this) {
            self::REGISTER => 'block_register',
            self::LOGIN => 'block_login',
            self::SMS => 'block_sms',
        };
    }

    /** 被擋時回給前台的錯誤碼（三種項目共用同一組） */
    public function errorCode(): int
    {
        return self::ERROR_CODE;
    }

    /** 被擋時回給前台的訊息（文案內含錯誤碼） */
    public function message(): string
    {
        return __(match ($this) {
            self::REGISTER => 'error_message.ip_restriction.register_blocked',
            self::LOGIN => 'error_message.ip_restriction.login_blocked',
            self::SMS => 'error_message.ip_restriction.sms_blocked',
        });
    }

    /**
     * 勾選項目 → 三個旗標欄位（未勾選一律寫 0，編輯時取消勾選要能生效）。
     *
     * @param  array<int, string>  $items
     * @return array<string, int>
     */
    public static function toColumns(array $items): array
    {
        $columns = [];
        foreach (self::cases() as $case) {
            $columns[$case->column()] = in_array($case->value, $items, true) ? 1 : 0;
        }

        return $columns;
    }

    /**
     * 三個旗標欄位 → 勾選項目（順序固定 註冊/登入/簡訊驗證，前端顯示不跳動）。
     *
     * @return array<int, string>
     */
    public static function fromModel(object $rule): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => (int) ($rule->{$case->column()} ?? 0) === 1),
        ));
    }
}
