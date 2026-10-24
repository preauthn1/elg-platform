<?php

declare(strict_types=1);

namespace App\Services\Agent\Report;

use App\Enums\StatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * SR02N「來源」支付商三層樹（target/tickets#4356 D9）：支付商 → 支付商戶 → 支付通道，一次回完供前端本地級聯。
 *
 * 規則（對照 API_Agent `AgentCashFlowRepository::getList`）：
 *  - 只列啟用中的支付商（cash_flows.status=1），且須掛有商戶。
 *  - 非 Admin 線依登入者 cash_flow_group_id 過濾商戶（群組為 0 時不過濾，同 getList）；Admin 線見全部。
 *  - 支付商名稱取登入者 customized_setting_group_id 的語系覆寫（cash_flow_names），無則 cash_flows.name。
 *  - 通道只取第一層 order_types（parent=0），名稱取語系覆寫（order_type_names），無則 order_types.name。
 *  - 商戶底下沒有通道就不出現第三層（children 為空陣列）；取款金流天然只有兩層。
 *
 * @knowledge rules/report-sr02n
 */
class Sr02nCashFlowTreeService
{
    /** order_types.parent＝0：第一層通道 */
    private const int TOP_LEVEL_ORDER_TYPE_PARENT = 0;

    /**
     * @return array<int, array{cash_flow_id: int, name: string, type: int, children: array<int, array{agent_cash_flow_id: int, name: string, children: array<int, array{order_type_id: int, name: string}>}>}>
     */
    public function tree(Agent $viewer, int $languageId): array
    {
        $groupId = !$viewer->isPlatformAdmin() && (int) $viewer->cash_flow_group_id > 0
            ? (int) $viewer->cash_flow_group_id
            : null;
        $nameGroupId = (int) $viewer->customized_setting_group_id;

        $merchantScope = static function (Builder|Relation $query) use ($groupId): void {
            if ($groupId !== null) {
                $query->where('agent_group_id', $groupId);
            }
        };

        $cashFlows = CashFlow::query()
            ->where('status', StatusEnum::ENABLE->value)
            ->whereHas('agentCashFlows', $merchantScope)
            ->with([
                'names' => static function (Relation $query) use ($languageId, $nameGroupId): void {
                    $query->where('language_id', $languageId)->where('agent_group_id', $nameGroupId);
                },
                'agentCashFlows' => static function (Relation $query) use ($merchantScope): void {
                    $merchantScope($query);
                    $query->orderBy('name')->orderBy('id');
                },
                'agentCashFlows.agentCashFlowOrderTypes' => static function (Relation $query): void {
                    $query->whereHas('orderType', static function (Builder $orderType): void {
                        $orderType->where('parent', self::TOP_LEVEL_ORDER_TYPE_PARENT);
                    })->orderBy('order_type_id');
                },
                'agentCashFlows.agentCashFlowOrderTypes.orderType',
                'agentCashFlows.agentCashFlowOrderTypes.agentCashFlowOrderTypeNames' => static function (Relation $query) use ($languageId): void {
                    $query->where('language_id', $languageId);
                },
            ])
            ->orderBy('type')
            ->orderBy('id')
            ->get();

        return $cashFlows->map(static fn (CashFlow $cashFlow): array => [
            'cash_flow_id' => (int) $cashFlow->id,
            'name' => (string) ($cashFlow->names->first()?->name ?? $cashFlow->name),
            'type' => (int) $cashFlow->type,
            'children' => $cashFlow->agentCashFlows->map(static fn (AgentCashFlow $merchant): array => [
                'agent_cash_flow_id' => (int) $merchant->id,
                'name' => (string) $merchant->name,
                'children' => $merchant->agentCashFlowOrderTypes
                    ->unique('order_type_id')
                    ->map(static fn (AgentCashFlowOrderType $channel): array => [
                        'order_type_id' => (int) $channel->order_type_id,
                        'name' => (string) ($channel->agentCashFlowOrderTypeNames->first()?->name ?? $channel->orderType?->name),
                    ])
                    ->values()
                    ->all(),
            ])->values()->all(),
        ])->values()->all();
    }
}
