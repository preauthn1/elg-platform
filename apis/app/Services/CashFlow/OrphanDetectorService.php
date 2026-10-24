<?php

declare(strict_types=1);

namespace App\Services\CashFlow;

use App\Enums\CashFlow\CashFlowScopeEnum;
use App\Enums\CashFlow\OrphanReasonEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * 偵測 agent_cash_flow_order_types 的孤立資料。
 *
 * 「孤立」定義：acfot 自身 status=1，但其上游鏈（cash_flow → order_type → agent_cash_flow）
 * 任一節點異常。詳細分類見 {@see OrphanReasonEnum}。
 */
class OrphanDetectorService
{
    /**
     * 找出所有狀態 = 1 的孤立 acfot。
     *
     * @param  int|null  $cashFlowId  限定特定金流商；null = 套用 $scope 範疇過濾
     * @param  CashFlowScopeEnum  $scope  範疇過濾；$cashFlowId 指定時忽略
     * @return Collection<int, stdClass> 每筆含 acfot 上下游欄位與 reason（OrphanReasonEnum）
     */
    public function findOrphans(
        ?int $cashFlowId = null,
        CashFlowScopeEnum $scope = CashFlowScopeEnum::APIS,
    ): Collection {
        $query = $this->baseQuery();

        if ($cashFlowId !== null) {
            $query->where('acf.cash_flow_id', $cashFlowId);
        } else {
            $scope->applyToQuery($query, 'acf.cash_flow_id');
        }

        return $query->get()->map(function (stdClass $row): stdClass {
            $row->reason = $this->classifyReason($row);

            return $row;
        })->filter(fn (stdClass $row): bool => $row->reason !== null)
            ->values();
    }

    /**
     * 取得 cash_flow 主體健康度（Layer 1）。
     */
    public function checkCashFlow(int $cashFlowId): ?stdClass
    {
        /** @var stdClass|null $row */
        $row = DB::table('cash_flows')
            ->where('id', $cashFlowId)
            ->select(['id', 'name', 'type', 'third_party_code', 'status'])
            ->first();

        return $row;
    }

    /**
     * 取得該 cash_flow 旗下異常的 order_types（Layer 2）。
     *
     * @return Collection<int, stdClass>
     */
    public function findUnhealthyOrderTypes(int $cashFlowId): Collection
    {
        return DB::table('order_types as ot')
            ->leftJoin('cash_flows as cf', 'cf.id', '=', 'ot.cash_flow_id')
            ->leftJoin('order_types as parent_ot', function ($join): void {
                $join->on('parent_ot.id', '=', 'ot.parent');
            })
            ->where('ot.cash_flow_id', $cashFlowId)
            ->where(function (Builder $q): void {
                $q->whereNull('cf.id')
                    ->orWhere('cf.status', 0)
                    ->orWhere('ot.status', 0)
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('ot.parent', '!=', 0)
                            ->whereNull('parent_ot.id');
                    })
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('ot.parent', '!=', 0)
                            ->where('parent_ot.status', 0);
                    });
            })
            ->select([
                'ot.id',
                'ot.name',
                'ot.cash_flow_id',
                'ot.parent',
                'ot.status as ot_status',
                'cf.status as cf_status',
                'parent_ot.status as parent_status',
            ])
            ->orderBy('ot.id')
            ->get();
    }

    /**
     * 取得該 cash_flow 旗下異常的 agent_cash_flows（Layer 3）。
     *
     * @return Collection<int, stdClass>
     */
    public function findUnhealthyAgentCashFlows(int $cashFlowId): Collection
    {
        return DB::table('agent_cash_flows as acf')
            ->leftJoin('cash_flows as cf', 'cf.id', '=', 'acf.cash_flow_id')
            ->where('acf.cash_flow_id', $cashFlowId)
            ->where(function (Builder $q): void {
                $q->whereNull('cf.id')
                    ->orWhere('cf.status', 0)
                    ->orWhere('acf.status', 0);
            })
            ->select([
                'acf.id',
                'acf.agent_group_id',
                'acf.currency_id',
                'acf.name',
                'acf.status as acf_status',
                'cf.status as cf_status',
            ])
            ->orderBy('acf.id')
            ->get();
    }

    /**
     * Layer 4 的核心查詢：JOIN 上游鏈，回傳每筆 acfot 及其上游狀態欄位。
     */
    private function baseQuery(): Builder
    {
        return DB::table('agent_cash_flow_order_types as acfot')
            ->join('agent_cash_flows as acf', 'acf.id', '=', 'acfot.agent_cash_flow_id')
            ->leftJoin('cash_flows as cf', 'cf.id', '=', 'acf.cash_flow_id')
            ->leftJoin('order_types as ot', 'ot.id', '=', 'acfot.order_type_id')
            ->leftJoin('order_types as parent_ot', 'parent_ot.id', '=', 'ot.parent')
            ->where('acfot.status', 1)
            ->where(function (Builder $q): void {
                $q->whereNull('cf.id')
                    ->orWhere('cf.status', 0)
                    ->orWhereNull('ot.id')
                    ->orWhere('ot.status', 0)
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('ot.parent', '!=', 0)->whereNull('parent_ot.id');
                    })
                    ->orWhere(function (Builder $q2): void {
                        $q2->where('ot.parent', '!=', 0)->where('parent_ot.status', 0);
                    })
                    ->orWhereColumn('ot.cash_flow_id', '!=', 'acf.cash_flow_id')
                    ->orWhere('acf.status', 0);
            })
            ->select([
                'acfot.id as acfot_id',
                'acfot.agent_cash_flow_id',
                'acfot.order_type_id',
                'acf.agent_group_id',
                'acf.currency_id',
                'acf.cash_flow_id as acf_cash_flow_id',
                'acf.status as acf_status',
                'ot.cash_flow_id as ot_cash_flow_id',
                'ot.status as ot_status',
                'ot.parent as ot_parent',
                'parent_ot.id as parent_ot_id',
                'parent_ot.status as parent_ot_status',
                'cf.status as cf_status',
                'cf.name as cash_flow_name',
            ])
            ->orderBy('acf.cash_flow_id')
            ->orderBy('acfot.id');
    }

    /**
     * 依優先順序判定該筆 acfot 的孤立成因。
     *
     * 順序：由外向內、由上游到下游，先報根本問題，避免重複觸發。
     */
    private function classifyReason(stdClass $row): ?OrphanReasonEnum
    {
        return match (true) {
            $row->cf_status === null => OrphanReasonEnum::L1_CASHFLOW_MISSING,
            (int) $row->cf_status === 0 => OrphanReasonEnum::L1_CASHFLOW_DISABLED,
            $row->ot_status === null => OrphanReasonEnum::L2_ORDER_TYPE_MISSING,
            (int) $row->ot_status === 0 => OrphanReasonEnum::L2_ORDER_TYPE_DISABLED,
            (int) $row->ot_parent !== 0 && $row->parent_ot_id === null => OrphanReasonEnum::L2_PARENT_MISSING,
            (int) $row->ot_parent !== 0 && (int) $row->parent_ot_status === 0 => OrphanReasonEnum::L2_PARENT_DISABLED,
            (int) $row->ot_cash_flow_id !== (int) $row->acf_cash_flow_id => OrphanReasonEnum::L2_CASHFLOW_MISMATCH,
            (int) $row->acf_status === 0 => OrphanReasonEnum::L3_ACF_DISABLED,
            default => null,
        };
    }
}
