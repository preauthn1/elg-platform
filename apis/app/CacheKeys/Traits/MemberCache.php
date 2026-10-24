<?php

declare(strict_types=1);

namespace App\CacheKeys\Traits;

trait MemberCache
{
    /**
     * 會員存取權杖快取
     */
    public static function memberAccessToken(string $token): string
    {
        return "member_access_token:[REDACTED]";
    }

    /**
     * 會員是否為 Telegram 用戶
     */
    public static function isTgUser(int $memberId): string
    {
        return "is_tg_user:{$memberId}";
    }

    /**
     * 會員輸贏記錄
     */
    public static function memberWinloses(int $memberId, int $vendorId, int $languageId, string $searchDay): string
    {
        return "member_winloses:{$memberId}:{$vendorId}:{$languageId}:{$searchDay}";
    }

    /**
     * 會員總有效投注
     */
    public static function memberTotalValidBets(int $memberId, string $searchDay): string
    {
        return "member_total_valid_bets:{$memberId}:{$searchDay}";
    }

    /**
     * 會員遊戲商帳戶
     */
    public static function memberVendorAccount(string $vendorTname, int $memberId): string
    {
        return "member_vendor_account:{$vendorTname}:{$memberId}";
    }

    /**
     * 會員 Google 驗證
     */
    public static function memberAuthGoogle(int $memberId): string
    {
        return "member_auth:google:{$memberId}";
    }

    /**
     * 遊客 token (目前在登入失敗時使用)
     */
    public static function guestValidateToken(string $token): string
    {
        return "guest_validate_token:[REDACTED]";
    }

    /**
     * 輸入手機或信箱不符合當前會員資料次數
     */
    public static function moblieEmailMatchFailCount(int $memberId): string
    {
        return "member_moblie_email_match_fail_count:{$memberId}";
    }
}
