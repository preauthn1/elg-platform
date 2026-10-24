<?php

declare(strict_types=1);

namespace App\CacheKeys\Traits;

trait AgentCache
{
    /**
     * API_Agent Authenticate middleware 的 token 快取（target/tickets#4518）。
     *
     * ⚠️ 這是 **Redis 原始 key**，只能用 Redis facade 直接 DEL，不能經 Cache facade（會再套 apis 自己的前綴）。
     * 格式＝API_Agent `config/cache.php` stores.redis_auth 的固定前綴 `agent_access_token`（對方
     * `AgentAccessToken:[REDACTED] ':' ＋ `AgentAccessToken:[REDACTED] cache 前綴：
     * 那是從 APP_NAME 算的，七站 .env 各不相同。兩邊同一台 redis、同一個 db（REDIS_DB 與對方 REDIS_DATABASE 皆 0，七站已核對）。
     * API_Agent 改前綴或 key 名時必須同步改這裡：刪錯 key 不會報錯，只是被踢的人在舊後台還能再用 5 分鐘。
     */
    public static function apiAgentAccessToken(string $token): string
    {
        return "agent_access_token:[REDACTED]";
    }

    /**
     * 代理 Google 2FA 設置畫面的金鑰／QRCode 暫存，與 API_Agent 共用（target/tickets#4518）。
     *
     * ⚠️ Redis 原始 key，值是 json_encode(['image' => …, 'secret' => …])，用 Redis facade 直接讀寫。
     * 格式＝API_Agent `REDIS_PREFIX`（各站皆為 Agent）＋ ':agent_auth:google:' ＋ agent id（App\REDIS::set 的組法），
     * 兩邊共用才能讓同一個人在新舊後台看到同一組 QRCode；開啟／重置後兩邊都會刪。
     */
    public static function legacyAgentAuthGoogle(int $agentId): string
    {
        return "Agent:agent_auth:google:{$agentId}";
    }

    /**
     * 代理設定
     */
    public static function agentSettings(int $agentId, int $languageId): string
    {
        return "AGENT_SETTINGS:{$agentId}:{$languageId}";
    }

    /**
     * 前台域名 工單4372
     */
    public static function legacyFrontendDomain(int $agentId): string
    {
        return "LEGACY_FRONTEND_DOMAIN:{$agentId}";
    }

    /**
     * 基本資訊內容 工單4104
     *
     * 以個性化組別為單位（同組共用），語系為變數：內容依當前語系取、
     * 未填才退英文，故不同語系須分開快取。
     */
    public static function basicInfoContents(int $settingGroupId, int $languageId): string
    {
        return "BASIC_INFO_CONTENTS:{$settingGroupId}:{$languageId}";
    }

    /**
     * 站點儀表板 工單4155
     *
     * D-1 日結資料不再變動，依「端點＋站點範圍＋UTC 區間」快取整包結果。
     */
    public static function siteDashboard(string $endpoint, string $scopeKey, string $startAt, string $endAt): string
    {
        return 'SITE_DASHBOARD:' . md5("{$endpoint}|{$scopeKey}|{$startAt}|{$endAt}");
    }

    /**
     * 代理維護狀態
     */
    public static function maintainAgents(): string
    {
        return 'maintain_agents';
    }

    /**
     * 代理域名
     */
    public static function agentDomain(string $domain): string
    {
        return "agent_domain:{$domain}";
    }

    /**
     * 代理遊戲列表
     */
    public static function agentGames(int $agentId, int $vendorId): string
    {
        return "agent_games:a{$agentId}:v{$vendorId}";
    }

    /**
     * 代理自定義設定
     */
    public static function agentCustomizedSetting(int $agentGroupId, int $settingKey): string
    {
        return "AgentCustomizedSetting:{$agentGroupId}:setting:{$settingKey}";
    }

    /**
     * 代理自定義設定群組
     */
    public static function agentCustomizedSettingGroup(int $agentGroupId, int $settingKey): string
    {
        return "AgentCustomizedSetting:group:{$agentGroupId}:setting:{$settingKey}";
    }

    /**
     * 代理設定定義
     */
    public static function agentSettingDefine(int $settingId): string
    {
        return "AgentSettingDefine:{$settingId}";
    }

    /**
     * 跑馬燈
     */
    public static function agentMarquee(string $domain, int $languageId, int $location = 0): string
    {
        return "agent:{$domain}:marquee:{$languageId}:loc:{$location}";
    }

    /**
     * 代理域名快取
     *
     * 原本在 Models/Agent.php 中
     */
    public static function agentModelAgentDomain(int $agentId): string
    {
        return "AgentModelAgentDomain:a{$agentId}";
    }

    /**
     * 代理全部遊戲類別的遊戲商列表
     */
    public static function agentGameTypeVendors(int $agentId): string
    {
        return "agent_game_type_vendors:{$agentId}";
    }

    /**
     * 代理 id ⇒ username（長效快取，id↔name 幾乎不變）
     */
    public static function agentUsername(int $agentId): string
    {
        return "agent_username:{$agentId}";
    }
}
