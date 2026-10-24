<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n\Segments;

use App\Data\Report\Sr02nFilter;
use App\Data\Report\Sr02nScope;
use App\Enums\Agent\ReservedAgentIdEnum;
use App\Enums\Report\Sr02nReportTypeSet;
use App\Enums\Report\Sr02nSegmentEnum;
use App\Enums\Transaction\TransactionLogAgentStatusEnum;
use App\Enums\Transaction\WalletTransactionLogAgentTypeEnum;
use App\Models\Agent;
use App\Models\WalletTransactionLogAgent;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * 代理佔成錢包段（wallet_transaction_log_agents la；對照 API_Agent `transactionAgentWalletModel`）。
 *
 * 操作者 f ＝ operator（須在登入者主帳號路徑下，或為 System 51）、對象 t ＝ agent_id。
 * 未指定代理時照 SR02 原碼只列「登入者主帳號的子帳號 ∪ System 51」的操作，**不含登入者本人**（沿用，不補）。
 *
 * 與 SR02 的差異：SR02 對 System 51 的列完全不看範圍（`f.hierarchy_path like ? or la.operator = 51`），
 * 非 Admin 登入會看到 System 對外線代理的佔成結算。本段改為 System 列須對象 t 在登入者可見範圍（scopePath）底下，
 * Admin 線（scopePath 為 null）不限。
 *
 * @knowledge rules/report-sr02n
 */
class AgentWalletSegment extends AbstractSr02nSegment
{
    public function segment(): Sr02nSegmentEnum
    {
        return Sr02nSegmentEnum::AGENT_WALLET;
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
        $systemId = ReservedAgentIdEnum::SYSTEM->value;

        $query = WalletTransactionLogAgent::query()->from('wallet_transaction_log_agents as la')->toBase()
            ->join('agents as t', 't.id', '=', 'la.agent_id')
            ->join('agents as f', function (JoinClause $join) use ($filter) {
                $join->on('f.id', '=', 'la.operator');
                // SR02 把執行者條件放在 join 內，照搬
                if ($filter->executionUsername !== '') {
                    $join->where('f.username', $filter->executionUsername);
                }
            })
            // 操作者在登入者主帳號路徑下；或為 System 51 且對象在登入者可見範圍內（Admin 線不限）
            ->where(function (Builder $where) use ($scope, $systemId) {
                $where->where('f.hierarchy_path', 'like', $scope->hostPath . '%')
                    ->orWhere(function (Builder $system) use ($scope, $systemId) {
                        $system->where('la.operator', $systemId)
                            ->when($scope->scopePath !== null, fn (Builder $q) => $q->where('t.hierarchy_path', 'like', $scope->scopePath . '%'));
                    });
            })
            ->where('la.created_at', '>=', $filter->startUtc)
            ->where('la.created_at', '<=', $filter->endUtc)
            ->whereIn('la.wallet_type_id', Sr02nReportTypeSet::AGENT_WALLET_WALLET_TYPES)
            ->whereIn('la.type', Sr02nReportTypeSet::AGENT_WALLET_ALL)
            ->where('la.status', TransactionLogAgentStatusEnum::SUCCESS->value);

        if ($scope->targetAgent !== null) {
            $query->where('t.username', $scope->targetAgent->username);
        } else {
            $query->where(function (Builder $where) use ($scope, $systemId) {
                $where->whereIn('f.id', Agent::query()->select('id')->where('host_id', $scope->hostId))
                    ->orWhere('f.id', $systemId);
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
            'balance' => 'la.balance',
            'operator_username' => 'f.username',
            'raw_status' => 'la.status',
            'remark' => 'la.remark',
        ];
    }

    protected function totalColumns(): array
    {
        return [
            'total_in' => $this->sumWhenIn('la.type', [WalletTransactionLogAgentTypeEnum::TRANSFER_IN->value], 'la.amount'),
        ];
    }
}
