<?php

declare(strict_types=1);

namespace App\Enums\CashFlow;

/**
 * agent_cash_flow_order_types 孤立資料的成因分類。
 *
 * 命名規則：L{層級}_{說明}
 *   - L1：cash_flows 層（金流商主體）
 *   - L2：order_types 層（含 parent-child 階層）
 *   - L3：agent_cash_flows 層
 *
 * 用於 check:cashflow-orphans 報告分類與 fix:cashflow-orphans 的 --reason 過濾。
 */
enum OrphanReasonEnum: string
{
    case L1_CASHFLOW_MISSING = 'L1_cashflow_missing';

    case L1_CASHFLOW_DISABLED = 'L1_cashflow_disabled';

    case L2_ORDER_TYPE_MISSING = 'L2_order_type_missing';

    case L2_ORDER_TYPE_DISABLED = 'L2_order_type_disabled';

    case L2_PARENT_MISSING = 'L2_parent_missing';

    case L2_PARENT_DISABLED = 'L2_parent_disabled';

    case L2_CASHFLOW_MISMATCH = 'L2_cashflow_mismatch';

    case L3_ACF_DISABLED = 'L3_acf_disabled';

    public function description(): string
    {
        return match ($this) {
            self::L1_CASHFLOW_MISSING => '所屬金流商不存在',
            self::L1_CASHFLOW_DISABLED => '所屬金流商已停用',
            self::L2_ORDER_TYPE_MISSING => '對應的 order_type 不存在',
            self::L2_ORDER_TYPE_DISABLED => '對應的 order_type 已停用',
            self::L2_PARENT_MISSING => 'order_type 的 parent 不存在',
            self::L2_PARENT_DISABLED => 'order_type 的 parent 已停用',
            self::L2_CASHFLOW_MISMATCH => 'order_type 與所屬 acf 的 cash_flow_id 不一致',
            self::L3_ACF_DISABLED => '所屬 agent_cash_flow 已停用',
        };
    }

    /**
     * 簡短中文標籤，供報告表格使用（保持窄欄寬）。
     */
    public function label(): string
    {
        return match ($this) {
            self::L1_CASHFLOW_MISSING => '[L1] 金流缺失',
            self::L1_CASHFLOW_DISABLED => '[L1] 金流停用',
            self::L2_ORDER_TYPE_MISSING => '[L2] 類型缺失',
            self::L2_ORDER_TYPE_DISABLED => '[L2] 類型停用',
            self::L2_PARENT_MISSING => '[L2] 上層缺失',
            self::L2_PARENT_DISABLED => '[L2] 上層停用',
            self::L2_CASHFLOW_MISMATCH => '[L2] cf 不一致',
            self::L3_ACF_DISABLED => '[L3] 渠道停用',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
