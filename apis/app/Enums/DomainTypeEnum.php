<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `domain_types` 網域類型（4035 域名管理）。
 *
 * 對應 `domains.domain_type_id`；FRONTEND 屬玩家站台（走 `agent_domains`），
 * 不在系統管理 → 域名管理的維護範圍內。
 */
enum DomainTypeEnum: int
{
    // 代理後台：需淺色 / 深色兩組 LOGO
    case AGENT_BACKEND = 1;

    // 前台（玩家站台，本功能不維護）
    case FRONTEND = 2;

    // 客服後台：僅需單一 LOGO（寫入 logo_lm）
    case CS_BACKEND = 3;

    /** 是否需要深色模式 LOGO（僅代理後台雙 LOGO）*/
    public function needsDarkLogo(): bool
    {
        return $this === self::AGENT_BACKEND;
    }

    /** 是否需要色系（僅客服後台套用主題色，代理後台不使用）*/
    public function needsThemeColor(): bool
    {
        return $this === self::CS_BACKEND;
    }
}
