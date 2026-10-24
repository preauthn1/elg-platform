<?php

declare(strict_types=1);

namespace App\CacheKeys;

use App\CacheKeys\Traits\AgentCache;
use App\CacheKeys\Traits\ExternalCache;
use App\CacheKeys\Traits\GameCache;
use App\CacheKeys\Traits\MemberCache;
use App\CacheKeys\Traits\RankCache;
use App\CacheKeys\Traits\VendorCache;
use App\CacheKeys\Traits\VendorServiceCache;

/**
 * 通用快取，需在同類型太多時抽離出去
 *
 * 🔴 **快取鍵集中在本類（與其 trait），TTL 一律取 {@see \App\Enums\General\CacheTtl} enum、
 * 含參數的鍵用 `Common::genCacheKey()`**——禁止在呼叫端手拼鍵字串或寫魔術數字 TTL，
 * 散落的鍵會讓「這份快取還有誰在寫」變成查不出來的問題。
 */
class CacheKeys
{
    use AgentCache,
        ExternalCache,
        GameCache,
        MemberCache,
        RankCache,
        VendorCache,
        VendorServiceCache;

    // ==================== 系統通知相關 Cache Keys ====================

    /**
     * 系統通知
     */
    public static function systemNotify(int $memberId): string
    {
        return "system_notify:{$memberId}";
    }

    /**
     * 會員最後活動時間更新降頻鎖（存在期間跳過寫入）
     */
    public static function memberLastActivity(int $memberId): string
    {
        return "member_last_activity:{$memberId}";
    }

    // ==================== 平台公告相關 Cache Keys ====================

    /**
     * 平台公告 (代理版本)
     */
    public static function platformAnnouncementAgent(int $agentId, int $languageId): string
    {
        return "platformAnnouncement:agent:{$agentId}:lang:{$languageId}";
    }

    /**
     * 平台公告未讀 (會員版本)
     */
    public static function platformAnnouncementMemberUnread(int $memberId): string
    {
        return "platformAnnouncement:member:{$memberId}:unread";
    }

    // ==================== 活動相關 Cache Keys ====================

    /**
     * 可用活動 detail IDs（依代理層級）
     */
    public static function availableEventDetailIds(array $agentIds): string
    {
        return 'available_event_detail_ids:' . implode(',', $agentIds);
    }

    /**
     * 活動場館遊戲（event/vendorGames）
     *
     * 帶 memberId 是必要的：回傳的 games.disable 取決於該會員的鎖分狀態，不可跨會員共用。
     */
    public static function eventGamesByVendor(int $memberId, int $languageId, int $vendorId): string
    {
        return "event_games_by_vendor:{$memberId}:{$languageId}:{$vendorId}";
    }

    /**
     * 可用活動 detail IDs（依代理層級，排除老虎機競賽以外的所有 action_id）
     */
    public static function availableEventDetailIdsExcludeSlotCompetition(array $agentIds): string
    {
        return 'available_event_detail_ids_exclude_slot_competition:' . implode(',', $agentIds);
    }

    /**
     * 可用活動 V2 detail IDs（依代理層級）
     */
    public static function availableEventV2DetailIds(array $agentIds): string
    {
        return 'available_event_v2_detail_ids:' . implode(',', $agentIds);
    }

    /**
     * Event V2 排程
     */
    public static function eventV2Schedule(): string
    {
        return 'event_v2_schedule';
    }

    // ==================== 訂單類型相關 Cache Keys ====================

    /**
     * 支付方式列表
     */
    public static function orderTypes(int $groupId, int $languageId): string
    {
        // v2：快取形狀自 OrderType 集合改為 AgentCashFlowOrderType 集合，
        // 升版隔離部署窗口內新舊 pod 對同一 key 的形狀互踩
        return "order_types:v2:group_{$groupId}:lang:{$languageId}";
    }

    /**
     * 訂單類型銀行
     */
    public static function orderTypeBanks(): string
    {
        return 'order_type_banks';
    }

    // ==================== 加密貨幣收款地址相關 Cache Keys ====================

    /**
     * 存款方式清單向金流商取址失敗後的冷卻鎖：存在期間該支付通道不再向金流商取址
     * （MemberCryptoWalletService::walletsForChannels）。以支付通道為單位：同一商戶某條通道的幣鏈設定錯了，
     * 不會擋到其他正常的通道；整個商戶故障時，每條通道每分鐘最多等一次逾時。
     */
    public static function cryptoAddressFetchCooldown(int $channelId): string
    {
        return "crypto_address_fetch_cooldown:acot_{$channelId}";
    }

    /**
     * 存款方式清單最近一次向金流商確認過的收款地址（MemberCryptoWalletService::walletsForChannels）。
     * 鍵含地址歸屬範圍（CCPayment 為 APP_ID 雜湊），商戶換了 APP_ID 就對不到舊值、立刻重新確認。
     */
    public static function cryptoAddressVerified(int $channelId, int $memberId, string $addressScope): string
    {
        return "crypto_address_verified:acot_{$channelId}:member_{$memberId}:scope_{$addressScope}";
    }

    /**
     * CCPayment 商戶在金流商後台啟用的代幣（幣別代號 → coinId 與鏈清單），代收與代付的幣鏈判定共用
     * （CCPaymentTrait::channelSpec／payoutSpec）；一商戶一份
     */
    public static function ccpaymentEnabledCoins(int $agentCashFlowId): string
    {
        return "ccpayment:enabled_coins:acf_{$agentCashFlowId}";
    }

    // ==================== 模板相關 Cache Keys ====================

    /**
     * 模板對應
     */
    public static function templateMap(): string
    {
        return 'template_map';
    }

    // ==================== Telegram Bot 相關 Cache Keys ====================

    /**
     * Telegram Bot 品牌域名
     */
    public static function tgbotBrandByDomain(string $domain): string
    {
        return "tgbot:getBrandByDomain:{$domain}";
    }

    // ==================== 代理控制器相關 Cache Keys ====================

    /**
     * 代理聯絡連結
     */
    public static function agentContactLink(string $domain): string
    {
        return "getContactLink:{$domain}"; // 使用 domain 作為快取 key，避免不同域名共用
    }

    /**
     * 代理分析代碼
     */
    public static function agentAnalyticsCode(int $agentId): string
    {
        return "getAnalyticsCode:{$agentId}";
    }

    // ==================== 錢包相關 Cache Keys ====================

    /**
     * 會員錢包交易記錄
     */
    public static function memberWalletTransactionLog(int $memberId, string $functionName): string
    {
        return "member:{$memberId}:{$functionName}";
    }

    // ==================== 其他 Cache Keys ====================

    /**
     * 跑馬燈服務
     */
    public static function marqueeService(string $functionName, int $agentId): string
    {
        return "MarqueeService:{$functionName}:a{$agentId}";
    }

    /**
     * 廣告
     */
    public static function advertise(int $agentId, int $pathId, int $languageId): string
    {
        return "advertisement:a{$agentId}:p{$pathId}:l{$languageId}";
    }

    /**
     * 貨幣列表
     */
    public static function currencyList(int $languageId): string
    {
        return "currency:list:{$languageId}";
    }

    /**
     * 跑馬燈
     */
    public static function marqueeWinPoints(string $domain, int $languageId, ?string $parent = null): string
    {
        return "marquee:win_points:domain_{$domain}:lang_{$languageId}" . ($parent ? ":parent_{$parent}" : '');
    }

    /**
     * 同個 path 每10分鐘最多告警1次
     */
    public static function pathAlertCount(string $path): string
    {
        return "path_alert_count:{$path}";
    }

    /**
     * 代理媒體設定
     */
    public static function agentMedia(int $agentId): string
    {
        return "agent_media:{$agentId}";
    }

    /**
     * 代理活動獎勵標題
     */
    public static function eventJackpotTitle(int $agentId, int $languageId): string
    {
        return "event_jackpot_title:{$agentId}:lang:{$languageId}";
    }

    /**
     * 版本資訊 (根據domain)
     */
    public static function versionByDomain(string $domain): string
    {
        return "version:domain:{$domain}";
    }

    /**
     * 代理後台選單 code ⇒ id 對應（功能級授權用）
     */
    public static function agentMenuCodeMap(): string
    {
        return 'agent_menu_code_map';
    }

    // ==================== 滑塊驗證相關 Cache Keys（4188）====================

    /**
     * 滑塊驗證答案（缺口 x 座標），一題一次
     */
    public static function sliderCaptchaAnswer(string $captchaId): string
    {
        return "slider_captcha:answer:{$captchaId}";
    }

    /**
     * 滑塊驗證通過憑證，發送驗證碼時用一次即失效
     */
    public static function sliderCaptchaToken(string $token): string
    {
        return "slider_captcha:token:[REDACTED]";
    }

    /**
     * 同 IP 滑塊驗證失敗次數
     */
    public static function sliderCaptchaFailCount(string $ip): string
    {
        return "slider_captcha:fail_count:{$ip}";
    }

    /**
     * 代理數據看板：貢獻佔比的分母（可視範疇的同期總計）。
     *
     * 平台端登入時分母是全站，正式站量體下這段聚合很貴且同一組條件會被反覆查（切成本開關、
     * 翻頁、開明細都可能重打），故短期快取。`$signature` 需含範疇與期間。
     */
    /** 代理數據看板的指標聚合結果（範疇＋區間＋時區＋是否分組） */
    public static function agentDashboardAggregate(string $signature): string
    {
        return 'agent_dashboard_aggregate:' . md5($signature);
    }

    /** SR28 代理營運彙總表的指標聚合結果（範疇＋區間＋時區），target/tickets#4362 */
    public static function agentOperationSummaryAggregate(string $signature): string
    {
        return 'agent_operation_summary_aggregate:' . md5($signature);
    }

    /** 平台累計統計（官方形象站首頁四個數字，依品牌代理線） */
    public static function platformStatistic(int $agentId): string
    {
        return 'platform_statistic:' . $agentId;
    }
}
