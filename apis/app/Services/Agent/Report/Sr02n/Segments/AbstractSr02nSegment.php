<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n\Segments;

use App\Data\Report\Sr02nFilter;
use App\Data\Report\Sr02nScope;
use App\Enums\CashFlow\BankTransactionLogSourceEnum;
use App\Enums\Report\Sr02nReportTypeSet;
use App\Models\BankTransactionLog;
use App\Services\Agent\Report\Sr02n\Sr02nPairCondition;
use App\Services\Agent\Report\Sr02n\Sr02nSourceLayerCondition;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * SR02N 各段共用：UNION 欄位契約、會員範圍 join、SELECT 組裝。
 *
 * 欄位契約（四段順序、數量、別名必須一致，主錢包段放第一決定 UNION 欄位型別）：
 * 型別／來源／狀態等顯示映射一律在 {@see \App\Http\Resources\Agent\Report\Sr02nRowResource} 做，不做在 SQL——
 * SR02 把語系字串拼進 DB::raw 在本專案是紅線，且 type 混 int 與字串會讓 UNION 整欄升成 varchar。
 *
 * 合計的手續費／實際金額（PM 2026-09-30 定案 B：洗分列全部計入，無提款單者手續費 0）：total_tax 只加有提款單的列，
 * total_actual 加所有洗分列（{@see \App\Enums\Report\Sr02nSegmentEnum::withdrawalTypes()}）的「變更點數 − 手續費」。
 *
 * 定案 B 之上再排除「被拒絕的洗分」（target/tickets#4493）：扣了點數但會員或代理最終沒拿到錢的洗分列，
 * 列表的手續費／實際金額為 null、兩個合計都不計；總開分／總洗分／差額照舊。被拒絕的洗分有兩類：
 *  1. 對應的取款訂單最終被拒絕或退款（{@see rejectedByOrder()}，主錢包段 w、代理額度段 aw）；
 *  2. 會員拒絕洗分（只有主錢包段，見 {@see MainWalletSegment}）。
 * 是否被拒絕由各段輸出 `is_rejected`（1／0），列表與合計共用 {@see rejectedCondition()} 收成的同一條 SQL 條件；
 * 該條件以 COALESCE 保證只有 0／1 兩值（不會出現 NULL 讓列表當「沒被拒絕」、合計卻因 `NOT (NULL)` 漏算），
 * 所以兩個合計等於列表逐列加總，實際金額合計 ≡ 總洗分 − 被拒絕列的變更點數 − 手續費合計。
 *
 * @knowledge rules/report-sr02n
 */
abstract class AbstractSr02nSegment implements Sr02nSegmentInterface
{
    public const array COLUMNS = [
        'id',
        'segment',
        'created_at',
        'raw_type',
        'transaction_no',
        'third_party_order_no',
        'agent_username',
        'to_agent_username',
        'member_username',
        'member_first_name',
        'member_nickname',
        'point',
        'balance',
        'order_id',
        'withdrawal_order_id',
        'ota_order_id',
        'payout_cash_flow_id',
        'payout_cash_flow_name',
        'deposit_cash_flow_name',
        'operator_username',
        'account_nickname',
        'raw_status',
        'remark',
        'withdrawal_tax',
        'withdrawal_rate',
        // 1＝被拒絕的洗分列（target/tickets#4493），其餘 0；沒有拒絕概念的段不列（輸出 NULL，視同 0）
        'is_rejected',
    ];

    public const array TOTAL_COLUMNS = ['total_in', 'total_out', 'total_tax', 'total_actual'];

    public function __construct(
        protected Sr02nPairCondition $pairCondition,
        protected Sr02nSourceLayerCondition $sourceLayer,
    ) {}

    public function listQuery(Sr02nFilter $filter, Sr02nScope $scope, int $languageId): Builder
    {
        $query = $this->baseQuery($filter, $scope, $languageId);
        $columns = $this->listColumns();

        foreach (self::COLUMNS as $alias) {
            $this->addColumn($query, $columns[$alias] ?? null, $alias);
        }

        return $query;
    }

    public function totalQuery(Sr02nFilter $filter, Sr02nScope $scope, int $languageId): Builder
    {
        $query = $this->baseQuery($filter, $scope, $languageId);
        $columns = $this->totalColumns();

        foreach (self::TOTAL_COLUMNS as $alias) {
            $this->addColumn($query, $columns[$alias] ?? '0', $alias);
        }

        return $query;
    }

    /** 本段 FROM／JOIN／WHERE（列表與合計共用，確保兩者篩選一致） */
    abstract protected function baseQuery(Sr02nFilter $filter, Sr02nScope $scope, int $languageId): Builder;

    /**
     * 列表欄位：別名 ⇒ 純欄位運算字串，或 [含 ? 的 SQL, bindings]；未列的別名輸出 NULL。
     *
     * @return array<string, string|array{0: string, 1: array<int, int|string>}>
     */
    abstract protected function listColumns(): array;

    /**
     * 合計欄位：同上；未列的別名輸出 0。
     *
     * @return array<string, string|array{0: string, 1: array<int, int|string>}>
     */
    abstract protected function totalColumns(): array;

    /** `'<segment>'`：段別常量欄（Enum 值，無外部輸入） */
    protected function segmentLiteral(): string
    {
        return "'" . $this->segment()->value . "'";
    }

    /**
     * `SUM(CASE WHEN <column> IN (?, …) THEN <amount> ELSE 0 END)`
     *
     * @param  array<int, int>  $types
     * @return array{0: string, 1: array<int, int>}
     */
    protected function sumWhenIn(string $column, array $types, string $amount): array
    {
        if ($types === []) {
            return ['0', []];
        }

        return $this->sumIf("{$column} IN (" . $this->placeholders($types) . ')', array_values($types), $amount);
    }

    /**
     * `SUM(CASE WHEN <condition> THEN <amount> ELSE 0 END)`；$bindings 依 $condition 內 `?` 的順序排列。
     *
     * @param  array<int, int>  $bindings
     * @return array{0: string, 1: array<int, int>}
     */
    protected function sumIf(string $condition, array $bindings, string $amount): array
    {
        return ["SUM(CASE WHEN {$condition} THEN {$amount} ELSE 0 END)", array_values($bindings)];
    }

    /**
     * 實際金額合計：`SUM(CASE WHEN <type> IN (洗分列型別) [AND NOT (<被拒絕>)] THEN <amount> - 手續費 ELSE 0 END)`。
     *
     * PM 2026-09-30 定案 B：洗分列全部計入，無提款單者手續費 0。手續費口徑與 {@see convertedTax()} 相同；
     * $withdrawal 為 null 表示本段沒有提款單（手續費恆 0）。
     * $rejected 為本段「被拒絕的洗分」條件（target/tickets#4493，須是 {@see rejectedCondition()} 的輸出），給了就排除這些列；
     * SQL 裡 `?` 的順序是型別在前、被拒絕條件在後，bindings 照同一順序接。
     *
     * @param  array{0: string, 1: array<int, int>}|null  $rejected
     * @return array{0: string, 1: array<int, int>}
     */
    protected function sumActual(string $typeColumn, string $amount, ?string $withdrawal, ?array $rejected = null): array
    {
        $types = $this->segment()->withdrawalTypes();
        if ($types === []) {
            return ['0', []];
        }

        $fee = $withdrawal === null ? '0' : 'COALESCE(' . $this->convertedTax($withdrawal) . ', 0)';
        $condition = "{$typeColumn} IN (" . $this->placeholders($types) . ')';
        $bindings = array_values($types);

        if ($rejected !== null) {
            $condition .= " AND NOT ({$rejected[0]})";
            $bindings = [...$bindings, ...$rejected[1]];
        }

        return $this->sumIf($condition, $bindings, "{$amount} - {$fee}");
    }

    /**
     * 「被拒絕的洗分」第一類：對應的取款訂單最終被拒絕或退款（{@see Sr02nReportTypeSet::REJECTED_WITHDRAWAL_ORDER_STATUSES}）。
     *
     * 必須寫成 `<w>.id IS NOT NULL AND <w>.status IN (…)`：沒有取款訂單的洗分列 left join 出來 w 全為 NULL，
     * 單寫 `<w>.status IN (…)` 會得到 NULL，外層 `NOT (…)` 仍是 NULL，CASE 走 ELSE，整列被當成被拒絕而漏算；
     * 先判 id IS NOT NULL 讓沒有取款訂單的列落在 FALSE（`FALSE AND NULL` 為 FALSE）。
     * 這只保證 w 這半不出 NULL；和其他條件 OR 起來後仍可能是 NULL，最終的 0／1 二值由 {@see rejectedCondition()} 保證。
     *
     * @return array{0: string, 1: array<int, int>}
     */
    protected function rejectedByOrder(string $withdrawal): array
    {
        $statuses = Sr02nReportTypeSet::REJECTED_WITHDRAWAL_ORDER_STATUSES;

        return [
            "({$withdrawal}.id IS NOT NULL AND {$withdrawal}.status IN (" . $this->placeholders($statuses) . '))',
            $statuses,
        ];
    }

    /**
     * 本段「被拒絕的洗分」最終條件：各類條件 OR 起來，再包成 `COALESCE((<sql>), 0)`，保證只有 0／1 兩值。
     *
     * 各類條件遇 NULL 欄位會得 NULL（例如 transaction_logs.order_id 允許 NULL，`l.order_id = ?` 得 NULL，
     * `FALSE OR NULL` 仍是 NULL）。不收成二值的話，列表 `CASE WHEN NULL` 走 ELSE 當成沒被拒絕、照算手續費，
     * 合計 `AND NOT (NULL)` 卻也走 ELSE 不計，合計就不等於列加總。NULL 一律視為「配不到」＝沒被拒絕（偏向不排除）。
     * 列表 {@see rejectedFlag()}、合計 total_tax 與 {@see sumActual()} 都必須用這裡的輸出。
     *
     * @param  array{0: string, 1: array<int, int>}  ...$conditions
     * @return array{0: string, 1: array<int, int>}
     */
    protected function rejectedCondition(array ...$conditions): array
    {
        $sql = implode(' OR ', array_column($conditions, 0));
        $bindings = array_merge(...array_column($conditions, 1));

        return ["COALESCE(({$sql}), 0)", array_values($bindings)];
    }

    /**
     * 列表 `is_rejected` 欄：`CASE WHEN <被拒絕> THEN 1 ELSE 0 END`。
     *
     * 只算條件、不管型別：非洗分列在 {@see \App\Services\Agent\Report\Sr02nReportService::attachMoney()} 本來就是 null，不看這欄。
     *
     * @param  array{0: string, 1: array<int, int>}  $rejected  {@see rejectedCondition()} 的輸出
     * @return array{0: string, 1: array<int, int>}
     */
    protected function rejectedFlag(array $rejected): array
    {
        return ["CASE WHEN {$rejected[0]} THEN 1 ELSE 0 END", $rejected[1]];
    }

    /**
     * `?, ?, …`（與 $values 等長）
     *
     * @param  array<int, int>  $values
     */
    protected function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** 換算後手續費：`TRUNCATE(<w>.tax * <w>.rate, 4)`（tax 為會員幣別，×rate 換成平台點數） */
    protected function convertedTax(string $withdrawal): string
    {
        return 'TRUNCATE(' . $withdrawal . '.tax * ' . $withdrawal . '.rate, ' . Sr02nReportTypeSet::MONEY_SCALE . ')';
    }

    /**
     * 會員兩段共用：join 會員（帳號精確，否則暱稱精確，二擇一）與其代理 a（可見範圍）。
     */
    protected function joinMemberScope(Builder $query, string $logAlias, Sr02nFilter $filter, Sr02nScope $scope): void
    {
        $query->join('members as m', function (JoinClause $join) use ($logAlias, $filter) {
            $join->on('m.id', '=', "{$logAlias}.member_id");
            if ($filter->memberUsername !== '') {
                $join->where('m.username', $filter->memberUsername);
            } elseif ($filter->memberNickname !== '') {
                $join->where('m.nickname', $filter->memberNickname);
            }
        })->join('agents as a', function (JoinClause $join) use ($scope) {
            $join->on('a.id', '=', 'm.parent');
            if ($scope->scopePath !== null) {
                $join->where('a.hierarchy_path', 'like', $scope->scopePath . '%');
            }
        });
    }

    /**
     * 會員兩段共用的存款鏈：orders o（僅 $orderDepositType 型別）→ 商戶 acf → 支付商 cf →
     * 收款帳戶 deposit_btl／deposit_ba → 支付商語系名稱 cfn（會員代理的個性化群組）。
     *
     * 同一訂單可能有多筆 bank_transaction_logs（dev 舊資料 56 組重複），只接最新一筆（MAX(id)），避免列重複。
     */
    protected function joinDepositChain(Builder $query, string $logAlias, int $orderDepositType, int $languageId): void
    {
        $query->leftJoin('orders as o', function (JoinClause $join) use ($logAlias, $orderDepositType) {
            $join->on("{$logAlias}.order_id", '=', 'o.id')
                ->where("{$logAlias}.type", $orderDepositType);
        })
            ->leftJoin('agent_cash_flows as acf', 'o.agent_cash_flow_id', '=', 'acf.id')
            ->leftJoin('cash_flows as cf', 'acf.cash_flow_id', '=', 'cf.id')
            ->leftJoin('bank_transaction_logs as deposit_btl', function (JoinClause $join) {
                $join->on('deposit_btl.order_id', '=', 'o.id')
                    ->where('deposit_btl.source', BankTransactionLogSourceEnum::SELF_DEPOSIT->value)
                    ->where('deposit_btl.id', '=', $this->latestBankLogId('o.id', BankTransactionLogSourceEnum::SELF_DEPOSIT));
            })
            ->leftJoin('bank_accounts as deposit_ba', 'deposit_ba.id', '=', 'deposit_btl.account_id')
            ->leftJoin('cash_flow_names as cfn', function (JoinClause $join) use ($languageId) {
                $join->on('cfn.agent_group_id', '=', 'a.customized_setting_group_id')
                    ->on('cfn.cash_flow_id', '=', 'o.cash_flow_id')
                    ->where('cfn.language_id', $languageId);
            });
    }

    /** 期間：依建立期間時比 orders.created_at（SR02 原樣），否則比紀錄本身的 created_at */
    protected function wherePeriod(Builder $query, string $logAlias, Sr02nFilter $filter): void
    {
        $column = $filter->isCreatedPeriod ? 'o.created_at' : "{$logAlias}.created_at";
        $query->whereBetween($column, [$filter->startUtc, $filter->endUtc]);
    }

    /**
     * 相關子查詢：某單號在 bank_transaction_logs 指定來源的最新一筆 id（join 條件用，走 order_id 索引）。
     *
     * @return \Illuminate\Database\Eloquent\Builder<BankTransactionLog>
     */
    protected function latestBankLogId(string $orderIdColumn, BankTransactionLogSourceEnum $source): \Illuminate\Database\Eloquent\Builder
    {
        return BankTransactionLog::query()
            ->from('bank_transaction_logs as btl_latest')
            ->selectRaw('MAX(btl_latest.id)')
            ->whereColumn('btl_latest.order_id', $orderIdColumn)
            ->where('btl_latest.source', $source->value);
    }

    /**
     * @param  string|array{0: string, 1: array<int, int|string>}|null  $expression
     */
    private function addColumn(Builder $query, string|array|null $expression, string $alias): void
    {
        if (is_array($expression)) {
            $query->selectRaw($expression[0] . ' as ' . $alias, $expression[1]);

            return;
        }

        // 純欄位運算或常量（無外部輸入）：DB::raw 不含任何值
        $query->addSelect(DB::raw(($expression ?? 'NULL') . ' as ' . $alias));
    }
}
