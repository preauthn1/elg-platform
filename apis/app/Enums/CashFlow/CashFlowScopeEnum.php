<?php

declare(strict_types=1);

namespace App\Enums\CashFlow;

use Illuminate\Database\Query\Builder;

/**
 * 過渡期金流商範疇：APIs (cf>62) / Legacy (cf<=62) / All。
 *
 * 用於 check:cashflow-orphans 等 APIs 維運命令的範疇縮窄，
 * 預設聚焦 APIs 自家以避開舊系統雜訊。
 *
 * @knowledge rules/agent-self-deposit
 */
enum CashFlowScopeEnum: string
{
    /**
     * cf_id 邊界：> 此值屬 APIs，<= 此值屬舊系統。
     * 當舊系統全數移植完成後可考慮移除此分界。
     *
     * 2026-05-12 決議：舊系統（cf <= 62）關聯的孤立資料不主動清理、由舊系統自負其責，
     * 因此 APIs 維運命令預設只看 cf > 62；要看 legacy 殘留一律走 opt-in 開關，
     * 不要把預設範疇放寬成全部，否則輸出會被舊系統雜訊淹沒。
     *
     * 同一條分界線也是跨 repo 的路由開關：新代理後台 agent-backend 的代付分流
     * （apps/web-ele/src/api/withdrawal.ts）與代理快速充值分流（apps/web-ele/src/api/agent-self.ts）
     * 都以「cash_flow_id > 62」決定打本專案還是舊 API_Agent；分界以下但由本專案承接的通道
     * 列在 APIS_TAKEOVER_CASH_FLOW_IDS，前端有一份同樣的名單，兩邊要一起改。
     * 本專案的代理端建單與代付入口用 isHandledByApis() 把不歸本專案的通道擋下，
     * 避免舊系統範疇的通道被送進來（有些金流商新舊系統都有實作，不擋會直接送出去）。
     */
    public const LEGACY_BOUNDARY_CASH_FLOW_ID = 62;

    /**
     * 分界以下、但代收或代付已由本專案承接的 cash_flow_id。
     * 58：TNGPIN 代收（D_TNGPIN）。舊系統 API_Agent 經 target/support 套件的 TngpinPay 也送得出去，
     * 改由本專案承接是讓代理端開分與會員端走同一套 TngPin 實作。
     */
    public const APIS_TAKEOVER_CASH_FLOW_IDS = [58];

    case APIS = 'apis';

    case LEGACY = 'legacy';

    case ALL = 'all';

    public function applyToQuery(Builder $query, string $column): void
    {
        if ($this === self::ALL) {
            return;
        }

        $query->where(
            $column,
            $this === self::APIS ? '>' : '<=',
            self::LEGACY_BOUNDARY_CASH_FLOW_ID,
        );
    }

    public function description(): string
    {
        return match ($this) {
            self::APIS => sprintf('APIs 專案範疇（cf > %d）', self::LEGACY_BOUNDARY_CASH_FLOW_ID),
            self::LEGACY => sprintf('舊系統範疇（cf ≤ %d）', self::LEGACY_BOUNDARY_CASH_FLOW_ID),
            self::ALL => '所有 cf（含舊系統與 APIs）',
        };
    }

    /**
     * 這個 cash_flow_id 的代收、代付是否由本專案承接：分界以上一律是，
     * 分界以下只有 APIS_TAKEOVER_CASH_FLOW_IDS 名單內的才是。
     */
    public static function isHandledByApis(int $cashFlowId): bool
    {
        return $cashFlowId > self::LEGACY_BOUNDARY_CASH_FLOW_ID
            || in_array($cashFlowId, self::APIS_TAKEOVER_CASH_FLOW_IDS, true);
    }

    /**
     * 通道不由本專案承接時回給前端的訊息。
     */
    public static function notHandledMessage(int $cashFlowId): string
    {
        return sprintf('此支付通道（cash_flow_id %d）過舊，APIs 無法支援', $cashFlowId);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
