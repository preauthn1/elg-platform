<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n\Segments;

use App\Data\Report\Sr02nFilter;
use App\Data\Report\Sr02nScope;
use App\Enums\Report\Sr02nReportTypeSet;
use App\Enums\Report\Sr02nSegmentEnum;
use App\Enums\Report\Sr02nSourceEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Models\WalletTransactionLog;
use Illuminate\Database\Query\Builder;

/**
 * 會員遊戲錢包段（wallet_transaction_logs wl；對照 API_Agent `transactionMemberWalletModel`）。
 *
 * 修正 SR02 兩個既有缺陷（計劃 A0）：SR02 本段沒 join withdrawal_orders／withdrawal_payout_records，
 * 卻在來源「客服系統」寫 `whereNull('w.id')`、來源「取款」寫 `wpr.cash_flow_id`，兩者都會 SQL 錯誤。
 * 本段改為：客服系統只留 `type in (6,8,9) and o.id is null`；遊戲錢包沒有取款列，來源取款整段不併。
 *
 * @knowledge rules/report-sr02n
 */
class GameWalletSegment extends AbstractSr02nSegment
{
    public function segment(): Sr02nSegmentEnum
    {
        return Sr02nSegmentEnum::GAME;
    }

    public function enabled(Sr02nFilter $filter): bool
    {
        if ($filter->source === Sr02nSourceEnum::ONLINE_PAYOUT) {
            return false;
        }

        return !$filter->hasPairs() || $filter->memberPairs() !== [];
    }

    protected function baseQuery(Sr02nFilter $filter, Sr02nScope $scope, int $languageId): Builder
    {
        $query = WalletTransactionLog::query()->from('wallet_transaction_logs as wl')->toBase();

        $this->joinMemberScope($query, 'wl', $filter, $scope);

        $this->joinDepositChain($query, 'wl', WalletTransactionEnum::GAME_WALLET_ORDER_DEPOSIT->value, $languageId);
        $this->wherePeriod($query, 'wl', $filter);

        $query
            // wl.operator 存的是操作代理 id（字串欄位）
            ->leftJoin('agents as operator', 'operator.id', '=', 'wl.operator')
            ->where('wl.status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->when($filter->executionUsername !== '', fn (Builder $q) => $q->where('operator.username', $filter->executionUsername));

        $this->applySource($query, $filter);
        $this->applyTransactionNo($query, $filter);
        $this->pairCondition->applyToMemberSegment($query, $filter, agentColumn: 'wl.agent_id', operatorColumn: 'wl.operator', operatorKey: 'id');

        return $query;
    }

    protected function listColumns(): array
    {
        return [
            'id' => 'wl.id',
            'segment' => $this->segmentLiteral(),
            'created_at' => 'wl.created_at',
            'raw_type' => 'wl.type',
            'transaction_no' => [
                'CASE wl.type WHEN ? THEN o.order_no ELSE wl.transaction_no END',
                [WalletTransactionEnum::GAME_WALLET_ORDER_DEPOSIT->value],
            ],
            'third_party_order_no' => 'o.third_party_order_no',
            'agent_username' => 'a.username',
            'to_agent_username' => "''",
            'member_username' => 'm.username',
            'member_first_name' => 'm.first_name',
            'member_nickname' => 'm.nickname',
            'point' => 'wl.point',
            'balance' => 'wl.balance',
            'order_id' => 'o.id',
            'deposit_cash_flow_name' => 'IFNULL(cfn.name, cf.name)',
            'operator_username' => 'operator.username',
            'account_nickname' => 'deposit_ba.account_nickname',
            'raw_status' => 'wl.status',
            'remark' => 'wl.remark',
        ];
    }

    protected function totalColumns(): array
    {
        return [
            'total_in' => $this->sumWhenIn('wl.type', Sr02nReportTypeSet::GAME_TOTAL_IN, 'wl.point'),
            'total_out' => $this->sumWhenIn('wl.type', Sr02nReportTypeSet::GAME_TOTAL_OUT, 'wl.point'),
            // 遊戲錢包沒有提款單：洗分列手續費 0，實際金額＝變更點數
            'total_actual' => $this->sumActual('wl.type', 'wl.point', null),
        ];
    }

    private function applySource(Builder $query, Sr02nFilter $filter): void
    {
        match ($filter->source) {
            Sr02nSourceEnum::CUSTOMER_SERVICE => $query->whereIn('wl.type', Sr02nReportTypeSet::GAME_CS)->whereNull('o.id'),
            Sr02nSourceEnum::ONLINE_DEPOSIT => $this->applyDepositSource($query, $filter),
            // 取款：enabled() 已排除，保險起見不回任何列
            Sr02nSourceEnum::ONLINE_PAYOUT => $query->whereRaw('0 = 1'),
            Sr02nSourceEnum::ALL => $query->whereIn('wl.type', Sr02nReportTypeSet::GAME_ALL),
        };
    }

    private function applyDepositSource(Builder $query, Sr02nFilter $filter): void
    {
        $query->where('wl.type', WalletTransactionEnum::GAME_WALLET_ORDER_DEPOSIT->value)->whereNotNull('o.id');
        $this->sourceLayer->applyDeposit($query, $filter);
    }

    /** 我方單號，或（來源存款）三方充值單號 */
    private function applyTransactionNo(Builder $query, Sr02nFilter $filter): void
    {
        if ($filter->transactionNo === '') {
            return;
        }

        $query->where(function (Builder $where) use ($filter) {
            $where->where('wl.transaction_no', $filter->transactionNo)
                ->when($filter->source === Sr02nSourceEnum::ONLINE_DEPOSIT, fn (Builder $q) => $q->orWhere('o.third_party_order_no', $filter->transactionNo));
        });
    }
}
