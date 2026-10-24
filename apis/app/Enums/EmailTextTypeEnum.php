<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 驗證碼郵件樣板類型(email_texts.type_id)
 *
 * 與簡訊樣板類型（params 表 type=sms_text_type）8 種完全對齊：value 同 params.value、
 * parameter() 同 params.parameter。前端以 parameter 對應語系鍵 `sms_text_type.{parameter}` 顯示名稱。
 */
enum EmailTextTypeEnum: int
{
    /** 資料驗證 */
    case VERIFICATION = 1;
    /** 忘記密碼 */
    case FORGET = 2;
    /** 找回密碼 */
    case RETRIEVE = 3;
    /** 洗分驗證 */
    case WITHDRAWAL = 4;
    /** 支付密碼 */
    case PAYMENT_PASSWORD = 5;
    /** 忘記支付密碼 */
    case FORGET_PAYMENT_PASSWORD = 6;
    /** 洗分資料驗證 */
    case WITHDRAWAL_VERIFICATION = 7;
    /** 會員註冊 */
    case MEMBER_REGISTER = 8;

    /**
     * params.parameter，前端語系鍵 sms_text_type.{parameter} 用
     */
    public function parameter(): string
    {
        return match ($this) {
            self::VERIFICATION => 'verification',
            self::FORGET => 'forget',
            self::RETRIEVE => 'retrieve',
            self::WITHDRAWAL => 'withdrawal',
            self::PAYMENT_PASSWORD => 'payment_password', // hardcode-ok: params.parameter 對照值（語系鍵），非密鑰
            self::FORGET_PAYMENT_PASSWORD => 'forget_payment_password', // hardcode-ok: params.parameter 對照值（語系鍵），非密鑰
            self::WITHDRAWAL_VERIFICATION => 'withdrawal_verification',
            self::MEMBER_REGISTER => 'member_register',
        };
    }

    /**
     * 驗證類型（ValidationCodeTypeEnum）→ 郵件樣板類型
     *
     * 1–8 同值。9 重新綁定／10 登入驗證／11 純驗證 沒有對應樣板，歸「資料驗證」；
     * 12 v2 註冊歸「會員註冊」（簡訊側 RegisterV2SendService 也是借用 type=8 樣板）。
     * 簡訊側是直接拿驗證類型當 type_id，9–12 查不到樣板；郵件側改動前對 9–12 都寄得出去（文字寫死），
     * 若照抄簡訊做法會讓這四條 email 流程直接壞掉，故在此收斂。
     */
    public static function fromValidationType(int $type): self
    {
        return match ($type) {
            ValidationCodeTypeEnum::REGISTER_V2->value => self::MEMBER_REGISTER,
            ValidationCodeTypeEnum::REBINDING_DATA->value,
            ValidationCodeTypeEnum::LOGIN_PHONE_EMAIL->value,
            ValidationCodeTypeEnum::VERIFY_CODE->value => self::VERIFICATION,
            default => self::tryFrom($type) ?? self::VERIFICATION,
        };
    }
}
