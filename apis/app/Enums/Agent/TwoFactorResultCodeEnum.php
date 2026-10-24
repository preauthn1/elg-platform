<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * 代理 Google 2FA 操作的業務結果碼（target/tickets#4518 自 API_Agent 搬入）。
 *
 * 數值刻意與 API_Agent 的錯誤碼相同：新後台的 2FA 彈窗（two-fa-otp-dialog、two-fa-setup-dialog）
 * 依 1098 判斷「驗證碼錯誤、可重試」並就地顯示，其他碼顯示後端訊息；同一套前端同時打新舊兩邊，碼一致才不必分流。
 * 回應走 ApiResponse::customCode()（HTTP 200），不走 ApiException（HTTP 4xx）：
 * HTTP 4xx 會被前端 request-client 丟成例外並全域 toast，彈窗就無法就地顯示錯誤讓人重試。
 */
enum TwoFactorResultCodeEnum: int
{
    /** 驗證碼錯誤（不鎖定，可重試） */
    case OTP_INVALID = 1098;

    /** 對象尚未開啟 Google 2FA */
    case TARGET_NOT_ENABLED = 1100;

    /** 操作者本人尚未開啟 Google 2FA（操作他人時要驗操作者的驗證碼） */
    case OPERATOR_NOT_ENABLED = 1120;

    /** 對象套用 admin 角色，不能由他人關閉／重置 */
    case TARGET_ADMIN_ROLE = 1128;

    /** 不能重置自己的 Google 2FA */
    case CANNOT_RESET_SELF = 1130;

    /** 本人已開啟 Google 2FA */
    case ALREADY_ENABLED = 1131;

    /** 對應各語系 error_message.php 的 agent_2fa 區塊 */
    public function messageKey(): string
    {
        return 'error_message.agent_2fa.' . match ($this) {
            self::OTP_INVALID => 'otp_invalid',
            self::TARGET_NOT_ENABLED => 'target_not_enabled',
            self::OPERATOR_NOT_ENABLED => 'operator_not_enabled',
            self::TARGET_ADMIN_ROLE => 'target_admin_role',
            self::CANNOT_RESET_SELF => 'cannot_reset_self',
            self::ALREADY_ENABLED => 'already_enabled',
        };
    }
}
