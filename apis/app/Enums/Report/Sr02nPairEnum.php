<?php

declare(strict_types=1);

namespace App\Enums\Report;

/**
 * SR02N 開洗分明細表的「篩選」配對（操作者 → 對象，target/tickets#4356）。
 *
 * 七個配對＋admin，多選為 OR；全不勾＝不過濾（不屬任何配對的列只在全不勾時出現）。
 * 判定規則見 {@see \App\Services\Agent\Report\Sr02n\Sr02nPairCondition}。
 */
enum Sr02nPairEnum: string
{
    case GA_TO_CS = 'ga_cs';                 // 總代對客服
    case GA_TO_AGENT = 'ga_agent';           // 總代對代理
    case GA_TO_MEMBER = 'ga_member';         // 總代對會員
    case CS_TO_AGENT = 'cs_agent';           // 客服對代理
    case CS_TO_MEMBER = 'cs_member';         // 客服對會員
    case AGENT_TO_AGENT = 'agent_agent';     // 代理對代理
    case AGENT_TO_MEMBER = 'agent_member';   // 代理對直屬會員
    case ADMIN = 'admin';                    // Admin 線做的全部開洗分（僅 Admin 線登入可選）

    /** 對象是會員：作用於會員兩段（主錢包、遊戲錢包） */
    public function targetsMember(): bool
    {
        return match ($this) {
            self::GA_TO_MEMBER, self::CS_TO_MEMBER, self::AGENT_TO_MEMBER, self::ADMIN => true,
            default => false,
        };
    }

    /** 對象是代理：作用於代理兩段（額度、佔成錢包） */
    public function targetsAgent(): bool
    {
        return match ($this) {
            self::GA_TO_CS, self::GA_TO_AGENT, self::CS_TO_AGENT, self::AGENT_TO_AGENT, self::ADMIN => true,
            default => false,
        };
    }
}
