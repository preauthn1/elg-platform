<?php

declare(strict_types=1);

namespace App\Enums\Report;

/**
 * SR02N 四段 UNION 的段別（對應 SR02 的四個 builder）。
 *
 * platform／wallet_type 為 SR02 回應欄位原值，前端明細彈窗依此決定打哪張表。
 */
enum Sr02nSegmentEnum: string
{
    case MAIN = 'main';                  // 會員主錢包 transaction_logs
    case GAME = 'game';                  // 會員遊戲錢包 wallet_transaction_logs
    case AGENT_CREDIT = 'agent_credit';  // 代理額度 transaction_log_agents
    case AGENT_WALLET = 'agent_wallet';  // 代理佔成錢包 wallet_transaction_log_agents

    /** 1＝會員、2＝代理 */
    public function platform(): int
    {
        return match ($this) {
            self::MAIN, self::GAME => 1,
            self::AGENT_CREDIT, self::AGENT_WALLET => 2,
        };
    }

    /** 1＝主錢包／額度、2＝遊戲錢包／佔成錢包 */
    public function walletType(): int
    {
        return match ($this) {
            self::MAIN, self::AGENT_CREDIT => 1,
            self::GAME, self::AGENT_WALLET => 2,
        };
    }

    /**
     * 本段的「洗分列」型別（與合計 total_out 同一組）：這些列一律計入手續費與實際金額。
     *
     * PM 2026-09-30 定案 B：洗分列全部計入，無提款單者手續費 0。佔成錢包段沒有洗分列（total_out 恆 0）。
     *
     * @return array<int, int>
     */
    public function withdrawalTypes(): array
    {
        return match ($this) {
            self::MAIN => Sr02nReportTypeSet::MAIN_TOTAL_OUT,
            self::GAME => Sr02nReportTypeSet::GAME_TOTAL_OUT,
            self::AGENT_CREDIT => Sr02nReportTypeSet::AGENT_CREDIT_TOTAL_OUT,
            self::AGENT_WALLET => [],
        };
    }

    public function isWithdrawalType(int $rawType): bool
    {
        return in_array($rawType, $this->withdrawalTypes(), true);
    }

    public function isMemberSegment(): bool
    {
        return $this->platform() === 1;
    }
}
