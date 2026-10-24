<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\OrderType;
use Illuminate\Support\Collection;

/**
 * @knowledge rules/payment-channel-management
 */
class AgentCashFlowListService
{
    /**
     * 取得代理金流渠道清單
     *
     * @return array{agent_cash_flow_list: Collection<int, array<string, mixed>>, cash_flow_list: Collection<int, CashFlow>}
     */
    public function getChannels(int $agentGroupId, int $languageId): array
    {
        $agentCashFlows = AgentCashFlow::query()
            ->where('agent_group_id', $agentGroupId)
            ->whereHas('cashFlow', fn ($q) => $q->where('status', 1)->whereIn('type', [1, 3]))
            ->with([
                'cashFlow',
                'agentCashFlowOrderTypes' => fn ($q) => $q
                    ->orderBy('sorts', 'desc')
                    ->with([
                        'orderType' => fn ($q) => $q
                            ->where('parent', 0)
                            ->with(['orderTypeNames', 'currency']),
                    ]),
            ])
            ->orderBy('id', 'asc')
            ->get();

        $list = $agentCashFlows
            ->flatMap(fn ($acf) => $this->expandRows($acf, $languageId))
            ->sort(fn ($a, $b) => $b['sorts'] <=> $a['sorts'] ?: $a['id'] <=> $b['id'])
            ->values();

        $cashFlowList = CashFlow::query()
            ->where('status', 1)
            ->select(['id', 'name'])
            ->get();

        return [
            'agent_cash_flow_list' => $list,
            'cash_flow_list' => $cashFlowList,
        ];
    }

    /**
     * 將一筆 AgentCashFlow 展開為一或多筆輸出行
     *
     * 對應原 LEFT JOIN 邏輯：
     * - type != 1：即使無 ACFOT 也輸出一筆（代付等渠道）
     * - type == 1：每筆 ACFOT 輸出一行，但 orderType 不存在則跳過
     *
     * @return array<int, array<string, mixed>>
     */
    private function expandRows(AgentCashFlow $acf, int $languageId): array
    {
        $cashFlow = $acf->cashFlow;
        $orderTypes = $acf->agentCashFlowOrderTypes;

        if ($orderTypes->isEmpty()) {
            return $cashFlow->type !== 1
                ? [$this->buildRow(
                    acf: $acf,
                    agentCashFlowOrderType: null,
                    cashFlow: $cashFlow,
                    languageId: $languageId,
                )]
                : [];
        }

        return $orderTypes
            ->flatMap(function (AgentCashFlowOrderType $agentCashFlowOrderType) use ($acf, $cashFlow, $languageId): array {
                $orderType = $agentCashFlowOrderType->orderType; // parent != 0 時 eager load 回傳 null

                // type=1 (支付) 必須有對應的 order_type
                if ($cashFlow->type === 1 && $orderType === null) {
                    return [];
                }

                return [$this->buildRow(
                    acf: $acf,
                    agentCashFlowOrderType: $agentCashFlowOrderType,
                    cashFlow: $cashFlow,
                    languageId: $languageId,
                    orderType: $orderType,
                )];
            })
            ->all();
    }

    /**
     * 組合單一輸出行資料
     *
     * @return array<string, mixed>
     */
    private function buildRow(
        AgentCashFlow $acf,
        ?AgentCashFlowOrderType $agentCashFlowOrderType,
        CashFlow $cashFlow,
        int $languageId,
        ?OrderType $orderType = null,
    ): array {
        $orderTypeNames = $orderType?->orderTypeNames ?? collect();

        // display_name：當前語言 → 英文（language_id=1）→ order_type 原始名稱
        $displayName = $orderTypeNames->firstWhere('language_id', $languageId)?->name
            ?? $orderTypeNames->firstWhere('language_id', 1)?->name
            ?? $orderType?->name;

        return [
            'id' => $acf->id,
            'tax' => Common::numAdd('0', $acf->tax ?? '0', 4),
            'tax_percentage' => Common::numMul($acf->tax_percentage ?? '0', 100, 2),
            'info' => $acf->info,
            'agent_cash_flow_name' => $acf->name,
            'rate' => Common::numAdd('0', $acf->rate ?? '0', 4),
            'cost' => Common::numMul($acf->cost ?? '0', 100, 2),
            'status' => $cashFlow->type === 1 ? $agentCashFlowOrderType?->status : $acf->status,
            'backend_display' => $cashFlow->type === 1 ? $agentCashFlowOrderType?->backend_display : $acf->backend_display,
            'is_recommend' => $agentCashFlowOrderType?->is_recommend,
            'agent_cash_flow_order_type_id' => $agentCashFlowOrderType?->id,
            'cash_flow_id' => $cashFlow->id,
            'name' => $cashFlow->name,
            'type' => $cashFlow->type,
            'sorts' => $agentCashFlowOrderType?->sorts ?? 0,
            'order_type_id' => $orderType?->id ?? 0,
            'order_type_name' => $orderType?->name,
            'display_name' => $displayName,
            'image_path' => $orderType?->image_path,
            'order_type_info' => $orderType?->info,
            'choose_bank' => $orderType?->choose_bank,
            'currency' => $orderType?->currency?->code,
            'order_type_names' => $orderTypeNames->pluck('name', 'language_id')->toArray(),
        ];
    }
}
