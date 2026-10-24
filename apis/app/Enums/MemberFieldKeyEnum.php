<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 會員註冊/登入欄位鍵 (agent_member_field_settings.field_key)
 */
enum MemberFieldKeyEnum: string
{
    case USERNAME = 'username';   // 玩家帳號

    case PASSWORD = [REDACTED]';   // 設定密碼(登入情境顯示為「密碼」)

    case CONFIRM_PASSWORD = [REDACTED]';   // 確認密碼

    case MOBILE = 'mobile';   // 手機號碼

    case EMAIL = 'email';   // 電子郵件

    case LAST_NAME = 'last_name';   // 真實姓名

    case BIRTHDAY = 'birthday';   // 出生日期

    case SMS_VERIFY_CODE = 'sms_verify_code';   // 手機驗證碼(簡訊驗證)

    case EMAIL_VERIFY_CODE = 'email_verify_code';   // 郵件驗證碼(Email驗證)

    case REFERRAL_CODE = 'referral_code';   // 推薦碼

    case BANK = 'bank';   // 銀行卡

    case ID_NUMBER = 'id_number';   // 證件號碼

    case ID_PHOTO = 'id_photo';   // 證件照片正反面
}
