<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Agent;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 會員活動篩選器（3967）。
 *
 * 依代收/代付總額·次數（member_hourly_statistics 聚合）與最後活動時間
 * （member_statistics + members.last_login）篩選會員，供後台「會員活動篩選器」使用。
 *
 * 效能策略：
 *  - Scope-first：聚合子查詢內部先 join agent 範疇，只聚合該 agent 下線的會員，
 *    絕不對整張 member_hourly_statistics（正式環境千萬級）做全表 GROUP BY。
 *  - 自適應：沒有金額/次數篩選時，先用輕量 base（members + member_statistics，走索引）
 *    分頁，再只對「當頁的 member_id」做聚合顯示；有金額/次數篩選才掛上 scoped 聚合過濾。
 */
class MemberActivityFilterService
{
    /** 數值篩選維度 => member_hourly_statistics 聚合欄位別名 */
    private const AMOUNT_FIELDS = [
        'total_deposit_amount' => 'da',
        'deposit_count' => 'dc',
        'total_withdraw_amount' => 'wa',
        'withdraw_count' => 'wc',
    ];

    /** 日期篩選維度 => 實際欄位 */
    private const DATE_FIELDS = [
        'last_login' => 'm.last_login',
        'last_deposit_at' => 'ms.last_deposit_at',
        'last_withdraw_at' => 'ms.last_withdraw_at',
        'last_bet_at' => 'ms.last_bet_at',
    ];

    public function filter(Agent $agent, array $filters, string $match, int $page, int $perPage): LengthAwarePaginator
    {
        $match = strtolower($match) === 'or' ? 'or' : 'and';
        $amountFilters = array_intersect_key($filters, self::AMOUNT_FIELDS);
        $dateFilters = array_intersect_key($filters, self::DATE_FIELDS);
        $scopePath = $this->scopePath($agent);

        return count($amountFilters) > 0
            ? $this->queryWithAggregate($scopePath, $amountFilters, $dateFilters, $match, $page, $perPage)
            : $this->queryLightThenAggregatePage($scopePath, $dateFilters, $match, $page, $perPage);
    }

    /**
     * 取得可視範疇的 hierarchy_path 前綴；admin(id=1)與其子帳號(host_id=1)回 null 表不限制。
     * 代理子帳號(host_id!=0)看其上層代理的範疇（比照 CheckinController）。
     */
    private function scopePath(Agent $agent): ?string
    {
        if ($agent->id == 1 || $agent->host_id == 1) {
            return null;
        }

        $path = $agent->hierarchy_path;
        if ($agent->host_id != 0) {
            $upperAgent = Agent::where('id', $agent->host_id)->first();
            $path = $upperAgent->hierarchy_path ?? $path;
        }

        return $path;
    }

    /** agent 範疇 base：members + member_statistics（皆走索引） */
    private function baseQuery(?string $scopePath): Builder
    {
        $query = DB::table('members as m')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->leftJoin('member_statistics as ms', 'ms.member_id', '=', 'm.id');

        if ($scopePath !== null) {
            $query->where('a.hierarchy_path', 'like', $scopePath . '%');
        }

        return $query;
    }

    /**
     * Scope-first 聚合子查詢：內部 join agent 範疇，只聚合該 agent 下線會員。
     */
    private function scopedAggregate(?string $scopePath): Builder
    {
        $sub = DB::table('member_hourly_statistics as mhs')
            ->join('members as m2', 'm2.id', '=', 'mhs.member_id')
            ->join('agents as a2', 'a2.id', '=', 'm2.parent')
            ->groupBy('mhs.member_id')
            ->select(
                'mhs.member_id',
                DB::raw('SUM(mhs.deposit_count) as dc'),
                DB::raw('SUM(mhs.deposit_amount) as da'),
                DB::raw('SUM(mhs.withdrawal_count) as wc'),
                DB::raw('SUM(mhs.withdrawal_amount) as wa'),
            );

        if ($scopePath !== null) {
            $sub->where('a2.hierarchy_path', 'like', $scopePath . '%');
        }

        return $sub;
    }

    /**
     * Path B：有金額/次數篩選 —— 掛上 scoped 聚合子查詢，數值與日期一起過濾後分頁。
     */
    private function queryWithAggregate(?string $scopePath, array $amountFilters, array $dateFilters, string $match, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery($scopePath)
            ->leftJoinSub($this->scopedAggregate($scopePath), 'agg', 'agg.member_id', '=', 'm.id');

        $this->applyFilters($query, $amountFilters, $dateFilters, $match, true);

        $total = (clone $query)->count('m.id');

        $rows = $query->select([
            'm.id as member_id', 'm.username', 'm.mobile', 'm.last_login',
            'ms.last_deposit_at', 'ms.last_withdraw_at', 'ms.last_bet_at', 'ms.last_activity_at',
            DB::raw('COALESCE(agg.dc, 0) as deposit_count'),
            DB::raw('COALESCE(agg.da, 0) as deposit_amount'),
            DB::raw('COALESCE(agg.wc, 0) as withdraw_count'),
            DB::raw('COALESCE(agg.wa, 0) as withdraw_amount'),
        ])
            ->orderBy('m.id', 'desc')
            ->forPage($page, $perPage)
            ->get();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * Path A：無金額/次數篩選 —— 先分頁輕量 base，再只對當頁 member_id 聚合顯示。
     */
    private function queryLightThenAggregatePage(?string $scopePath, array $dateFilters, string $match, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery($scopePath);
        $this->applyFilters($query, [], $dateFilters, $match, false);

        $total = (clone $query)->count('m.id');

        $rows = $query->select([
            'm.id as member_id', 'm.username', 'm.mobile', 'm.last_login',
            'ms.last_deposit_at', 'ms.last_withdraw_at', 'ms.last_bet_at', 'ms.last_activity_at',
        ])
            ->orderBy('m.id', 'desc')
            ->forPage($page, $perPage)
            ->get();

        // 只對當頁的 member_id 做聚合（走 member_hourly_statistics 的 member_id 索引）
        $ids = $rows->pluck('member_id')->all();
        $aggMap = [];
        if (count($ids) > 0) {
            $aggRows = DB::table('member_hourly_statistics')
                ->whereIn('member_id', $ids)
                ->groupBy('member_id')
                ->select(
                    'member_id',
                    DB::raw('SUM(deposit_count) as deposit_count'),
                    DB::raw('SUM(deposit_amount) as deposit_amount'),
                    DB::raw('SUM(withdrawal_count) as withdraw_count'),
                    DB::raw('SUM(withdrawal_amount) as withdraw_amount'),
                )
                ->get();
            foreach ($aggRows as $a) {
                $aggMap[$a->member_id] = $a;
            }
        }

        $rows->transform(function ($r) use ($aggMap) {
            $agg = $aggMap[$r->member_id] ?? null;
            $r->deposit_count = $agg->deposit_count ?? 0;
            $r->deposit_amount = $agg->deposit_amount ?? 0;
            $r->withdraw_count = $agg->withdraw_count ?? 0;
            $r->withdraw_amount = $agg->withdraw_amount ?? 0;

            return $r;
        });

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /** 依 match(and/or) 套用日期與數值篩選 */
    private function applyFilters(Builder $query, array $amountFilters, array $dateFilters, string $match, bool $hasAgg): void
    {
        if ($match === 'and') {
            foreach ($dateFilters as $key => $f) {
                $this->applyDate($query, 'and', self::DATE_FIELDS[$key], $f);
            }
            if ($hasAgg) {
                foreach ($amountFilters as $key => $f) {
                    $this->applyAmount($query, 'and', 'COALESCE(agg.' . self::AMOUNT_FIELDS[$key] . ', 0)', $f);
                }
            }

            return;
        }

        // or：全部條件包在同一個 closure 內 OR
        $query->where(function ($q) use ($amountFilters, $dateFilters, $hasAgg) {
            foreach ($dateFilters as $key => $f) {
                $this->applyDate($q, 'or', self::DATE_FIELDS[$key], $f);
            }
            if ($hasAgg) {
                foreach ($amountFilters as $key => $f) {
                    $this->applyAmount($q, 'or', 'COALESCE(agg.' . self::AMOUNT_FIELDS[$key] . ', 0)', $f);
                }
            }
        });
    }

    /** 數值:gt(>=) / lte(<=) / between（$expr 為靜態欄位運算式，值以綁定參數帶入） */
    private function applyAmount(Builder $query, string $bool, string $expr, array $f): void
    {
        $method = $bool === 'or' ? 'orWhereRaw' : 'whereRaw';
        $op = $f['op'] ?? 'gt';

        if ($op === 'between') {
            $query->{$method}("{$expr} BETWEEN ? AND ?", [(float) ($f['value'] ?? 0), (float) ($f['value2'] ?? 0)]);
        } elseif ($op === 'lte') {
            $query->{$method}("{$expr} <= ?", [(float) ($f['value'] ?? 0)]);
        } else {
            $query->{$method}("{$expr} >= ?", [(float) ($f['value'] ?? 0)]);
        }
    }

    /** 日期區間:col BETWEEN start 00:00:00 AND end 23:59:59（$col 為靜態欄位，值以綁定參數帶入） */
    private function applyDate(Builder $query, string $bool, string $col, array $f): void
    {
        if (empty($f['start']) || empty($f['end'])) {
            return;
        }
        $method = $bool === 'or' ? 'orWhereRaw' : 'whereRaw';
        // 日期以後台時區（GMT+8）日界解讀，轉 UTC 再比：DB 存 UTC，台北凌晨的資料才不會算到前一天（target/tickets#4492）
        $backendTimezone = config('custom.backend.timezone');
        $query->{$method}("{$col} BETWEEN ? AND ?", [
            Carbon::parse($f['start'] . ' 00:00:00', $backendTimezone)->toSystemTime()->toDateTimeString(),
            Carbon::parse($f['end'] . ' 23:59:59', $backendTimezone)->toSystemTime()->toDateTimeString(),
        ]);
    }
}
