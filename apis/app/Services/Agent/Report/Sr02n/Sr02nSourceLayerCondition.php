<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n;

use App\Data\Report\Sr02nFilter;
use App\Enums\Report\Sr02nReportTypeSet;
use Illuminate\Database\Query\Builder;

/**
 * SR02N「來源」支付商三層過濾（target/tickets#4356 D9）：支付商 cash_flows → 支付商戶 agent_cash_flows → 支付通道 order_types。
 *
 * 只在來源為存款（3.X）／取款（4.X）時由各段呼叫，與 SR02 一致。未帶的層級不過濾。
 *
 * @knowledge rules/report-sr02n
 */
readonly class Sr02nSourceLayerCondition
{
    /**
     * 存款：支付商比對 `acf.cash_flow_id`、商戶比對 `o.agent_cash_flow_id`、通道比對 `o.order_type_id`。
     */
    public function applyDeposit(Builder $query, Sr02nFilter $filter, string $order = 'o', string $agentCashFlow = 'acf'): void
    {
        $query->when($filter->cashFlowId !== null, fn (Builder $q) => $q->where("{$agentCashFlow}.cash_flow_id", $filter->cashFlowId))
            ->when($filter->agentCashFlowId !== null, fn (Builder $q) => $q->where("{$order}.agent_cash_flow_id", $filter->agentCashFlowId))
            ->when($filter->orderTypeId !== null, fn (Builder $q) => $q->where("{$order}.order_type_id", $filter->orderTypeId));
    }

    /**
     * 取款（取款無通道層）：
     *  - 預付網（cash_flow_id=18）走 ota_orders：支付商由呼叫端以型別 22＋ota_orders 存在判定，商戶比對 `oo.agent_cash_flow_id`。
     *  - 自助洗分（cash_flow_id=6）不比對 `wpr.cash_flow_id`（SR02 原規則：自助洗分沒有代付紀錄的金流商）。
     *  - 其餘支付商比對 `wpr.cash_flow_id`；商戶比對 `wpr.agent_cash_flow_id`。
     */
    public function applyPayout(Builder $query, Sr02nFilter $filter, string $payoutRecord = 'wpr', string $otaOrder = 'oo'): void
    {
        if ($this->isOtaPayout($filter)) {
            $query->when($filter->agentCashFlowId !== null, fn (Builder $q) => $q->where("{$otaOrder}.agent_cash_flow_id", $filter->agentCashFlowId));

            return;
        }

        $query->when(
            $filter->cashFlowId !== null && $filter->cashFlowId !== Sr02nReportTypeSet::SELF_WITHDRAWAL_CASH_FLOW_ID,
            fn (Builder $q) => $q->where("{$payoutRecord}.cash_flow_id", $filter->cashFlowId),
        )->when(
            $filter->agentCashFlowId !== null,
            fn (Builder $q) => $q->where("{$payoutRecord}.agent_cash_flow_id", $filter->agentCashFlowId),
        );
    }

    /** 取款支付商為預付網（OTA）：主錢包段改查型別 22＋ota_orders */
    public function isOtaPayout(Sr02nFilter $filter): bool
    {
        return $filter->cashFlowId === Sr02nReportTypeSet::OTA_CASH_FLOW_ID;
    }
}
