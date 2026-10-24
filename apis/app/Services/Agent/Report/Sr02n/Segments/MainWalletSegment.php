<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n\Segments;

use App\Data\Report\Sr02nFilter;
use App\Data\Report\Sr02nScope;
use App\Enums\CashFlow\BankTransactionLogSourceEnum;
use App\Enums\Report\Sr02nReportTypeSet;
use App\Enums\Report\Sr02nSegmentEnum;
use App\Enums\Report\Sr02nSourceEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\TransactionLog;
use App\Models\WithdrawalPayoutRecord;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * 會員主錢包段（transaction_logs l；對照 API_Agent `transactionMemberModel`）。
 *
 * 隱藏規則逐條沿用 SR02：成功狀態 1000、型別清單、orders／withdrawal_orders／ota_orders 以型別限定 join、
 * is_created_period 改用 orders.created_at、單號可比三方單號（依來源）。
 *
 * 被拒絕的洗分（target/tickets#4493）＝提款單最終拒絕／退款（w）或會員拒絕洗分（{@see refusedByMember()}），見 {@see rejected()}。
 *
 * @knowledge rules/report-sr02n
 */
class MainWalletSegment extends AbstractSr02nSegment
{
    /** 客服手動洗分沒有取款訂單：transaction_logs.order_id 為 0 */
    private const int NO_ORDER_ID = 0;

    public function segment(): Sr02nSegmentEnum
    {
        return Sr02nSegmentEnum::MAIN;
    }

    public function enabled(Sr02nFilter $filter): bool
    {
        return !$filter->hasPairs() || $filter->memberPairs() !== [];
    }

    protected function baseQuery(Sr02nFilter $filter, Sr02nScope $scope, int $languageId): Builder
    {
        $query = TransactionLog::query()->from('transaction_logs as l')->toBase();

        $this->joinMemberScope($query, 'l', $filter, $scope);
        $this->joinDepositChain($query, 'l', TransactionEnum::ORDER_DEPOSIT->value, $languageId);
        $this->joinPayout($query);
        $this->wherePeriod($query, 'l', $filter);

        $query->where('l.status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->when($filter->executionUsername !== '', fn (Builder $q) => $q->where('l.operator', $filter->executionUsername));

        $this->applySource($query, $filter);
        $this->applyTransactionNo($query, $filter);
        $this->pairCondition->applyToMemberSegment($query, $filter, agentColumn: 'l.agent_id', operatorColumn: 'l.operator', operatorKey: 'username');

        return $query;
    }

    protected function listColumns(): array
    {
        return [
            'id' => 'l.id',
            'segment' => $this->segmentLiteral(),
            'created_at' => 'l.created_at',
            'raw_type' => 'l.type',
            'transaction_no' => [
                'CASE l.type WHEN ? THEN o.order_no ELSE l.transaction_no END',
                [TransactionEnum::ORDER_DEPOSIT->value],
            ],
            'third_party_order_no' => [
                'CASE l.type WHEN ? THEN w.third_party_order_no ELSE o.third_party_order_no END',
                [TransactionEnum::WITHDRAWAL->value],
            ],
            'agent_username' => 'a.username',
            'to_agent_username' => "''",
            'member_username' => 'm.username',
            'member_first_name' => 'm.first_name',
            'member_nickname' => 'm.nickname',
            'point' => 'l.point',
            'balance' => 'l.balance',
            'order_id' => 'o.id',
            'withdrawal_order_id' => 'w.id',
            'ota_order_id' => 'oo.id',
            'payout_cash_flow_id' => 'wcf.id',
            'payout_cash_flow_name' => 'wcf.name',
            'deposit_cash_flow_name' => 'IFNULL(cfn.name, cf.name)',
            'operator_username' => 'l.operator',
            'account_nickname' => 'COALESCE(deposit_ba.account_nickname, ba.account_nickname)',
            'raw_status' => 'l.status',
            'remark' => 'l.remark',
            'withdrawal_tax' => 'w.tax',
            'withdrawal_rate' => 'w.rate',
            'is_rejected' => $this->rejectedFlag($this->rejected()),
        ];
    }

    protected function totalColumns(): array
    {
        $rejected = $this->rejected();

        return [
            'total_in' => $this->sumWhenIn('l.type', Sr02nReportTypeSet::MAIN_TOTAL_IN, 'l.point'),
            'total_out' => $this->sumWhenIn('l.type', Sr02nReportTypeSet::MAIN_TOTAL_OUT, 'l.point'),
            'total_tax' => $this->sumIf("w.id IS NOT NULL AND NOT ({$rejected[0]})", $rejected[1], $this->convertedTax('w')),
            'total_actual' => $this->sumActual('l.type', 'l.point', 'w', $rejected),
        ];
    }

    /**
     * 本段「被拒絕的洗分」：取款訂單最終拒絕／退款，或會員拒絕洗分（target/tickets#4493）；
     * 經 {@see rejectedCondition()} 收成 0／1 二值（transaction_logs.order_id 允許 NULL，NULL 視為配不到）。
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private function rejected(): array
    {
        return $this->rejectedCondition($this->rejectedByOrder('w'), $this->refusedByMember());
    }

    /**
     * 「被拒絕的洗分」第二類：客服手動洗分（型別 5、無取款訂單）被會員拒絕洗分（型別 11）。
     *
     * 兩列在 transaction_logs 上沒有任何關聯欄位（鏈結只存在客服聊天 DB），只能配對：
     * 存在一筆成功的拒絕洗分 r，同會員、同點數、在本列之後，且本列是 r 之前「同會員同點數」最近的一筆無單洗分
     * （兩者之間沒有另一筆同點數的無單洗分 l2）。同一筆洗分被重複拒絕時，多筆 r 都指回同一列，只沖這一列一次。
     * id 遞增即代表先後。
     *
     * 沒選一對一序列配對（第 N 筆拒絕配第 N 筆洗分）：dev 上的重複拒絕是秒級連按的重送——客服 socket `confirm()`
     * 寫完型別 11 後段例外回滾、聊天端狀態留在 4 讓會員可重按（例：會員 48191 對同一筆洗分的拒絕列 224027923／924／925
     * 間隔 3 秒、224027942～945 四筆在 17 秒內）；序列配對會把前面幾筆各自獨立、會員其實收下的洗分全沖掉。
     * 代價：「同一筆被重複拒絕」與「兩筆同額洗分都被拒絕」在資料上無法區分，兩種寫法都有誤差，選了對重送安全的這種
     * （後者只會沖到一列，另一列照算手續費與實際金額）。
     *
     * 不設時間窗：客服 socket 排程 `TransactionRefund` 每 5 分鐘跑一次，洗分等待會員回應超過 10 分鐘就系統退還；
     * 但會員 `event_mode>0` 時會被跳過而一直等（dev 實測拒絕與洗分最長間隔 2322 秒），上限不固定，訂多少都可能漏沖。
     * 後果：拒絕落在報表期間之後時，已結束期間的手續費／實際金額合計會被事後改寫（洗分列在期間內、拒絕列在期間外仍沖）。
     *
     * 型別 5 寫在條件裡而不是交給外層：洗分列型別還含扣分 36（同樣 order_id=0）與預付網 22，它們不是客服手動洗分，不能被拒絕洗分沖掉。
     * 子查詢以 query builder 組再取 toSql()／getBindings()，避免手寫 SQL 漏綁定；
     * 走 transaction_logs(member_id, type, status, created_at) 索引。
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private function refusedByMember(): array
    {
        $success = TransactionStatusEnum::STATUS_SUCCESS->value;

        $closerWithdrawal = TransactionLog::query()->from('transaction_logs as l2')->toBase()
            ->selectRaw('1')
            ->whereColumn('l2.member_id', 'l.member_id')
            ->where('l2.type', TransactionEnum::WITHDRAWAL->value)
            ->where('l2.status', $success)
            ->where('l2.order_id', self::NO_ORDER_ID)
            ->whereColumn('l2.point', 'l.point')
            ->whereColumn('l2.id', '>', 'l.id')
            ->whereColumn('l2.id', '<', 'r.id');

        $refusal = TransactionLog::query()->from('transaction_logs as r')->toBase()
            ->selectRaw('1')
            ->whereColumn('r.member_id', 'l.member_id')
            ->where('r.type', TransactionEnum::REFUSE_TO_WITHDRAWAL->value)
            ->where('r.status', $success)
            ->whereColumn('r.point', 'l.point')
            ->whereColumn('r.id', '>', 'l.id')
            ->whereNotExists($closerWithdrawal);

        /** @var array<int, int> $bindings */
        $bindings = $refusal->getBindings();

        return [
            '(l.type = ? AND l.order_id = ? AND EXISTS (' . $refusal->toSql() . '))',
            [TransactionEnum::WITHDRAWAL->value, self::NO_ORDER_ID, ...$bindings],
        ];
    }

    /**
     * 取款鏈：withdrawal_orders（僅型別 5）→ 付款帳戶 → 成功代付紀錄 → 代付支付商；預付網 ota_orders（僅型別 22）。
     *
     * 一張提款單可能有多筆成功代付紀錄（tpower／vworld 各 15 張）、多筆付款帳戶異動，兩者都只接最新一筆（MAX(id)），
     * 否則提款列會重複、手續費與合計重算。
     */
    private function joinPayout(Builder $query): void
    {
        $query->leftJoin('withdrawal_orders as w', function (JoinClause $join) {
            $join->on('w.id', '=', 'l.order_id')
                ->where('l.type', TransactionEnum::WITHDRAWAL->value);
        })
            ->leftJoin('bank_transaction_logs as btl', function (JoinClause $join) {
                $join->on('btl.order_id', '=', 'w.id')
                    ->where('btl.source', BankTransactionLogSourceEnum::SELF_WITHDRAWAL->value)
                    ->where('btl.id', '=', $this->latestBankLogId('w.id', BankTransactionLogSourceEnum::SELF_WITHDRAWAL));
            })
            ->leftJoin('bank_accounts as ba', 'ba.id', '=', 'btl.account_id')
            ->leftJoin('withdrawal_payout_records as wpr', function (JoinClause $join) {
                $join->on('wpr.withdrawal_order_id', '=', 'w.id')
                    ->where('wpr.is_success_payout', WithdrawalPayoutStatusEnum::SUCCESS->value)
                    ->where('wpr.id', '=', WithdrawalPayoutRecord::query()
                        ->from('withdrawal_payout_records as wpr_latest')
                        ->selectRaw('MAX(wpr_latest.id)')
                        ->whereColumn('wpr_latest.withdrawal_order_id', 'w.id')
                        ->where('wpr_latest.is_success_payout', WithdrawalPayoutStatusEnum::SUCCESS->value));
            })
            ->leftJoin('cash_flows as wcf', 'wpr.cash_flow_id', '=', 'wcf.id')
            ->leftJoin('ota_orders as oo', function (JoinClause $join) {
                $join->on('oo.id', '=', 'l.order_id')
                    ->where('l.type', TransactionEnum::OTA_DEDUCTION->value);
            });
    }

    private function applySource(Builder $query, Sr02nFilter $filter): void
    {
        match ($filter->source) {
            Sr02nSourceEnum::CUSTOMER_SERVICE => $query->whereIn('l.type', Sr02nReportTypeSet::MAIN_CS)
                ->whereNull('o.id')
                ->whereNull('w.id'),
            Sr02nSourceEnum::ONLINE_DEPOSIT => $this->applyDepositSource($query, $filter),
            Sr02nSourceEnum::ONLINE_PAYOUT => $this->applyPayoutSource($query, $filter),
            Sr02nSourceEnum::ALL => $query->whereIn('l.type', Sr02nReportTypeSet::MAIN_ALL),
        };
    }

    private function applyDepositSource(Builder $query, Sr02nFilter $filter): void
    {
        $query->whereIn('l.type', Sr02nReportTypeSet::MAIN_DEPOSIT)->whereNotNull('o.id');
        $this->sourceLayer->applyDeposit($query, $filter);
    }

    private function applyPayoutSource(Builder $query, Sr02nFilter $filter): void
    {
        if ($this->sourceLayer->isOtaPayout($filter)) {
            $query->where('l.type', TransactionEnum::OTA_DEDUCTION->value)->whereNotNull('oo.id');
        } else {
            $query->where('l.type', TransactionEnum::WITHDRAWAL->value)->whereNotNull('w.id');
        }

        $this->sourceLayer->applyPayout($query, $filter);
    }

    /** 我方單號，或（來源存款）三方充值單號、（來源取款）三方代付單號 */
    private function applyTransactionNo(Builder $query, Sr02nFilter $filter): void
    {
        if ($filter->transactionNo === '') {
            return;
        }

        $query->where(function (Builder $where) use ($filter) {
            $where->where('l.transaction_no', $filter->transactionNo)
                ->when($filter->source === Sr02nSourceEnum::ONLINE_DEPOSIT, fn (Builder $q) => $q->orWhere('o.third_party_order_no', $filter->transactionNo))
                ->when($filter->source === Sr02nSourceEnum::ONLINE_PAYOUT, fn (Builder $q) => $q->orWhere('w.third_party_order_no', $filter->transactionNo));
        });
    }
}
