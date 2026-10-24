<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n\Segments;

use App\Data\Report\Sr02nFilter;
use App\Data\Report\Sr02nScope;
use App\Enums\Report\Sr02nReportTypeSet;
use App\Enums\Report\Sr02nSegmentEnum;
use App\Enums\Report\Sr02nSourceEnum;
use App\Enums\Transaction\TransactionLogAgentStatusEnum;
use App\Enums\Transaction\TransactionLogAgentTypeEnum;
use App\Models\TransactionLogAgent;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * 代理額度段（transaction_log_agents la；對照 API_Agent `transactionAgentModel`）。
 *
 * 操作者 f ＝ from_agent_id（須在登入者主帳號路徑下）、對象 t ＝ to_agent_id。
 * 未指定代理時只列 f ＝ 登入者主帳號或其子帳號；指定代理時 f.username ＝ 該代理。
 * 代理自助洗分（type=2）的 order_id 指 withdrawal_orders.id（欄位註解寫 withdrawal_order_agents 已過時，
 * 見 API_Agent WithdrawalController 建單流程），以此帶出手續費；只認 member_id=0 的提款單。
 * 被拒絕的洗分（target/tickets#4493）只有「提款單 aw 最終拒絕／退款」一類，代理自助洗分沒有「拒絕洗分」。
 *
 * @knowledge rules/report-sr02n
 */
class AgentCreditSegment extends AbstractSr02nSegment
{
    /** 代理自助洗分的提款單 withdrawal_orders.member_id 固定為 0（非會員提款） */
    private const int AGENT_WITHDRAWAL_MEMBER_ID = 0;

    public function segment(): Sr02nSegmentEnum
    {
        return Sr02nSegmentEnum::AGENT_CREDIT;
    }

    public function enabled(Sr02nFilter $filter): bool
    {
        if ($filter->hasMemberFilter() || $filter->source->isCashFlow()) {
            return false;
        }

        return !$filter->hasPairs() || $filter->agentPairs() !== [];
    }

    protected function baseQuery(Sr02nFilter $filter, Sr02nScope $scope, int $languageId): Builder
    {
        $query = TransactionLogAgent::query()->from('transaction_log_agents as la')->toBase()
            ->join('agents as t', 't.id', '=', 'la.to_agent_id')
            ->join('agents as f', function (JoinClause $join) use ($scope) {
                $join->on('f.id', '=', 'la.from_agent_id')
                    ->where('f.hierarchy_path', 'like', $scope->hostPath . '%');
            })
            ->leftJoin('withdrawal_orders as aw', function (JoinClause $join) {
                // 代理自助洗分建單一律 member_id=0（API_Agent WithdrawalController::WithdrawalOrder::create）；
                // 限定 member_id=0 擋掉舊列 order_id 撞到會員提款單 id 的情形（dev 實測 2023 年 System 列 10 筆）
                $join->on('aw.id', '=', 'la.order_id')
                    ->where('la.type', TransactionLogAgentTypeEnum::WITHDRAWAL->value)
                    ->where('aw.member_id', self::AGENT_WITHDRAWAL_MEMBER_ID);
            })
            ->where('la.created_at', '>=', $filter->startUtc)
            ->where('la.created_at', '<=', $filter->endUtc)
            // 跟會員洗分一樣，主錢包轉入佔成錢包不記入
            ->where('la.type', '!=', TransactionLogAgentTypeEnum::MAIN_WALLET_TO_AGENT_WALLET->value)
            ->where('la.status', TransactionLogAgentStatusEnum::SUCCESS->value)
            ->when(
                $filter->source === Sr02nSourceEnum::CUSTOMER_SERVICE,
                fn (Builder $q) => $q->whereNotIn('la.type', Sr02nReportTypeSet::AGENT_CREDIT_CS_EXCLUDE),
            )
            ->when($filter->executionUsername !== '', fn (Builder $q) => $q->where('la.operator', $filter->executionUsername));

        if ($scope->targetAgent !== null) {
            $query->where('f.username', $scope->targetAgent->username);
        } else {
            $query->where(function (Builder $where) use ($scope) {
                $where->where('f.id', $scope->hostId)->orWhere('f.host_id', $scope->hostId);
            });
        }

        $this->pairCondition->applyToAgentSegment($query, $filter);

        return $query;
    }

    protected function listColumns(): array
    {
        return [
            'id' => 'la.id',
            'segment' => $this->segmentLiteral(),
            'created_at' => 'la.created_at',
            'raw_type' => 'la.type',
            'transaction_no' => "''",
            'third_party_order_no' => "''",
            'agent_username' => 'f.username',
            'to_agent_username' => 't.username',
            'member_username' => "''",
            'member_first_name' => "''",
            'member_nickname' => 't.nickname',
            'point' => 'la.amount',
            'balance' => '(la.to_limit_of_credit - la.to_used_credit)',
            'withdrawal_order_id' => 'aw.id',
            'operator_username' => 'la.operator',
            'raw_status' => 'la.status',
            'remark' => 'la.remark',
            'withdrawal_tax' => 'aw.tax',
            'withdrawal_rate' => 'aw.rate',
            'is_rejected' => $this->rejectedFlag($this->rejected()),
        ];
    }

    protected function totalColumns(): array
    {
        $rejected = $this->rejected();

        return [
            'total_in' => $this->sumWhenIn('la.type', Sr02nReportTypeSet::AGENT_CREDIT_TOTAL_IN, 'la.amount'),
            'total_out' => $this->sumWhenIn('la.type', Sr02nReportTypeSet::AGENT_CREDIT_TOTAL_OUT, 'la.amount'),
            'total_tax' => $this->sumIf("aw.id IS NOT NULL AND NOT ({$rejected[0]})", $rejected[1], $this->convertedTax('aw')),
            'total_actual' => $this->sumActual('la.type', 'la.amount', 'aw', $rejected),
        ];
    }

    /**
     * 本段「被拒絕的洗分」：洗分訂單 aw 最終拒絕／退款（target/tickets#4493），經 {@see rejectedCondition()} 收成 0／1 二值。
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private function rejected(): array
    {
        return $this->rejectedCondition($this->rejectedByOrder('aw'));
    }
}
