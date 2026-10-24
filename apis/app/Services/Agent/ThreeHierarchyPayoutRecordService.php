<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\ThreeHierarchyBonusReport;
use App\Models\ThreeHierarchyWithdrawLog;
use App\Services\Agent\Concerns\ResolvesAgentScopePath;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 三層代派發紀錄（4199，由 API_Agent `getPayoutRecord` 移植並強化）。
 *
 * 資料源 three_hierarchy_bonus_report（日報流水，一列＝受益人×貢獻者×遊戲商×遊戲類別×結算日），
 * 主表聚合為「受益人 × 結算日」一列；會員受益列 agent_id=0、代理受益列 member_id=0（沿 SP 口徑）。
 *
 * 效能（TPower 25M 列、31 天窗 65 萬列實測；查詢 5 秒紅線、後台不用快取、schema 不動）：
 *  - 全量 group＋join 的 count／取頁／兩卡片四趟總帳 ~30 秒 ⇒ admin 無帳號篩選走「快路徑」：
 *    ①每側「卡片＋分頁 total」合併為單趟純表掃描（SUM 可加性免 group、COUNT DISTINCT 出 total）
 *    ②取頁改「逐批（summary_time）湊頁」——只 group 湊滿當頁所需的批次（首頁通常一批），
 *      免全期 65 萬列 group；③只對當頁列 join 補帳號。大期間總帳 ~30s → 約 7~8s
 *   （65 萬列 COUNT DISTINCT 為 schema 不動下的物理下限，已記錄為 5 秒紅線例外）；
 *    短期間（今日／本週，日常主用）<1s。
 *  - 有帳號篩選或非 admin scope 走 join 路徑（過濾後量小）。
 *  - 孤兒列（member/agent 已刪的殘留日報）快路徑如實顯示、帳號「—」。
 *
 * 時區：本報表固定 GMT+8 結算（不隨前端時區），DB 存 UTC，入參 -8h、出參 +8h。
 *
 * 提現單號（工單場景 B）：withdraw_logs 與 bonus_report 無外鍵（派發讀的是累計桶、
 * 非日報），以「同會員＋該列派發時間之後最早一筆收據」時序配對；自動派發為全額清空桶，
 * 該列金額數學上必包含於該收據，僅前台手動部分提領情境可能高估。單號金額可能
 * 涵蓋多個結算日的合併派發，不可拿來逐列對帳。
 */
class ThreeHierarchyPayoutRecordService
{
    use ResolvesAgentScopePath;

    private const TZ_OFFSET_HOURS = 8;

    /**
     * @param  array{agent: string, member: string, identity: string, start_time: string, end_time: string}  $filters
     * @return array{rows: array<int, object>, total: int, summary: array<string, array<string, int|string>>}
     */
    public function query(Agent $agent, array $filters, int $page, int $perPage): array
    {
        $scopePath = $this->scopePath($agent);
        $identity = $this->effectiveIdentity($filters);
        $startUtc = $this->toUtc($filters['start_time']);
        $endUtc = $this->toUtc($filters['end_time']);

        // 快路徑條件：join 只為 scope／帳號篩選與補名——admin 無帳號篩選時全部可純表
        if ($scopePath === null && $filters['agent'] === '' && $filters['member'] === '') {
            return $this->queryFast($identity, $startUtc, $endUtc, $page, $perPage);
        }

        return $this->queryJoined($scopePath, $filters, $identity, $startUtc, $endUtc, $page, $perPage);
    }

    // ─────────────────────── 快路徑（純表運算＋逐批湊頁＋當頁補名） ───────────────────────

    /** @return array{rows: array<int, object>, total: int, summary: array<string, array<string, int|string>>} */
    private function queryFast(string $identity, string $startUtc, string $endUtc, int $page, int $perPage): array
    {
        // 單趟 ROLLUP 掃描同時取得：兩側卡片、分頁 total、批次清單（TPower 31 天實測
        // 6.75s，vs 分側分趟 10.4s）；SUM 免 group 數學等值、COUNT DISTINCT 出組數
        [$stats, $batches] = $this->fastCombinedScan($startUtc, $endUtc);

        $memberRows = (int) ($stats->m_rows ?? 0);
        $agentRows = (int) ($stats->a_rows ?? 0);
        $total = match ($identity) {
            'member' => $memberRows,
            'agent' => $agentRows,
            default => $memberRows + $agentRows,
        };

        $rows = $this->fastPageRows($batches, $identity, $startUtc, $endUtc, $page, $perPage);
        $rows = $this->attachDisplayNames($rows);
        $rows = $this->attachWithdrawOrderNo($rows);

        return [
            'rows' => $rows,
            'total' => $total,
            'summary' => [
                'member' => $identity === 'agent'
                    ? $this->zeroSummary('member_count')
                    : $this->buildSideSummary($stats, 'm', 'member_count'),
                'agent' => $identity === 'member'
                    ? $this->zeroSummary('agent_count')
                    : $this->buildSideSummary($stats, 'a', 'agent_count'),
            ],
        ];
    }

    /**
     * 單趟 ROLLUP 掃描：GROUP BY summary_time WITH ROLLUP——明細列＝批次清單
     * （逐批湊頁用）、總計列（GROUPING=1）＝兩側卡片與分頁 total，一次全拿。
     *
     * @return array{0: object, 1: array<int, object>}
     */
    private function fastCombinedScan(string $startUtc, string $endUtc): array
    {
        $rows = DB::table('three_hierarchy_bonus_report')
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<=', $endUtc)
            ->groupByRaw('summary_time WITH ROLLUP')
            ->selectRaw(
                'summary_time, GROUPING(summary_time) as is_total, MAX(created_at) as last_payout,'
                . ' SUM(IF(agent_id = 0, rebate, 0)) as m_rebate,'
                . ' SUM(IF(agent_id = 0, down_rebate, 0)) as m_down_rebate,'
                . ' SUM(IF(agent_id = 0, down2_rebate, 0)) as m_down2_rebate,'
                . ' SUM(IF(agent_id = 0, rebate + down_rebate + down2_rebate, 0)) as m_reward,'
                . ' COUNT(DISTINCT IF(agent_id = 0, member_id, NULL)) as m_people,'
                . " COUNT(DISTINCT IF(agent_id = 0, CONCAT(summary_time, '|', member_id), NULL)) as m_rows,"
                . ' SUM(IF(member_id = 0, rebate, 0)) as a_rebate,'
                . ' SUM(IF(member_id = 0, down_rebate, 0)) as a_down_rebate,'
                . ' SUM(IF(member_id = 0, down2_rebate, 0)) as a_down2_rebate,'
                . ' SUM(IF(member_id = 0, rebate + down_rebate + down2_rebate, 0)) as a_reward,'
                . ' COUNT(DISTINCT IF(member_id = 0, agent_id, NULL)) as a_people,'
                . " COUNT(DISTINCT IF(member_id = 0, CONCAT(summary_time, '|', agent_id), NULL)) as a_rows",
            )
            ->get();

        $stats = $rows->firstWhere('is_total', 1) ?? (object) [];
        $batches = $rows->where('is_total', 0)
            ->sortByDesc('last_payout')
            ->values()
            ->all();

        return [$stats, $batches];
    }

    /** @return array<string, int|string> */
    private function buildSideSummary(object $stats, string $prefix, string $countKey): array
    {
        return [
            'rebate' => Common::numAdd('0', (string) ($stats->{$prefix . '_rebate'} ?? 0), 4),
            'down_rebate' => Common::numAdd('0', (string) ($stats->{$prefix . '_down_rebate'} ?? 0), 4),
            'down2_rebate' => Common::numAdd('0', (string) ($stats->{$prefix . '_down2_rebate'} ?? 0), 4),
            'reward' => Common::numAdd('0', (string) ($stats->{$prefix . '_reward'} ?? 0), 4),
            $countKey => (int) ($stats->{$prefix . '_people'} ?? 0),
        ];
    }

    /**
     * 逐批（summary_time）湊頁：只 group 湊滿 offset＋perPage 所需的批次——首頁通常只碰
     * 最新一批（單批走 summary_time 索引、~2 萬列 group <0.2s），免全期 65 萬列 group。
     * 迴圈上限＝期間批次數（32 天上限 ⇒ 最多 ~32 輪，且湊滿即停），非逐列 N+1。
     * 批序以 MAX(created_at) desc 排（補跑批次的派發時間較新，會正確排前）。
     *
     * @param  array<int, object>  $batches
     * @return array<int, object>
     */
    private function fastPageRows(array $batches, string $identity, string $startUtc, string $endUtc, int $page, int $perPage): array
    {
        $needed = $page * $perPage;
        $collected = [];

        foreach ($batches as $batch) {
            if (count($collected) >= $needed) {
                break;
            }

            $parts = [];
            if ($identity !== 'member') {
                $parts[] = $this->fastBatchSideQuery('agent', $batch->summary_time, $startUtc, $endUtc);
            }
            if ($identity !== 'agent') {
                $parts[] = $this->fastBatchSideQuery('member', $batch->summary_time, $startUtc, $endUtc);
            }

            $union = array_shift($parts);
            foreach ($parts as $part) {
                $union->unionAll($part);
            }

            $batchRows = DB::query()->fromSub($union, 's')
                ->select('s.*')
                ->orderBy('s.created_at', 'desc')
                ->get()
                ->all();

            array_push($collected, ...$batchRows);
        }

        return array_slice($collected, ($page - 1) * $perPage, $perPage);
    }

    /** 單批單側的純表 group（走 summary_time 索引等值，批內 ~數萬列） */
    private function fastBatchSideQuery(string $side, string $summaryTime, string $startUtc, string $endUtc): Builder
    {
        $idColumn = $side === 'member' ? 'member_id' : 'agent_id';
        $otherZero = $side === 'member' ? 'agent_id' : 'member_id';

        return DB::table('three_hierarchy_bonus_report')
            ->where('summary_time', $summaryTime)
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<=', $endUtc)
            // 「+0」表達式遮蔽：讓優化器不可用 member_id/agent_id 索引——否則會棄
            // summary_time 索引改掃 0 值索引（數百萬列，TPower 實測 52s vs 0.1s）。
            // 純常量表達式無使用者輸入；FORCE INDEX 因各站索引名不一致不可用。
            ->whereRaw("{$otherZero} + 0 = 0")
            ->groupBy($idColumn)
            ->select(
                DB::raw("'{$side}' as identity"),
                "{$idColumn} as target_id",
                // 批內 summary_time 同值（where 等值），MAX 取值免字串插值進 raw
                DB::raw('MAX(summary_time) as summary_time'),
                DB::raw('SUM(rebate) as rebate'),
                DB::raw('SUM(down_rebate) as down_rebate'),
                DB::raw('SUM(down2_rebate) as down2_rebate'),
                DB::raw('SUM(rebate + down_rebate + down2_rebate) as reward'),
                DB::raw('SUM(bet + down_bet + down2_bet) as calc_base'),
                DB::raw('SUM(rebate_type = 2) as lose_rows'),
                DB::raw('MAX(created_at) as created_at'),
            );
    }

    /**
     * 只對當頁列補帳號名（join 從全表降到每頁筆數）。
     * 孤兒列（member/agent 已刪的殘留日報）帳號顯示「—」，如實呈現不隱藏。
     *
     * @param  array<int, object>  $rows
     * @return array<int, object>
     */
    private function attachDisplayNames(array $rows): array
    {
        $memberIds = [];
        $agentIds = [];
        foreach ($rows as $row) {
            if ($row->identity === 'member') {
                $memberIds[] = (int) $row->target_id;
            } else {
                $agentIds[] = (int) $row->target_id;
            }
        }

        $memberNames = $memberIds === []
            ? collect()
            : DB::table('members')->whereIn('id', array_unique($memberIds))->pluck('username', 'id');
        $agentNames = $agentIds === []
            ? collect()
            : DB::table('agents')->whereIn('id', array_unique($agentIds))->pluck('username', 'id');

        foreach ($rows as $row) {
            $row->username = $row->identity === 'member'
                ? ($memberNames[$row->target_id] ?? '—')
                : ($agentNames[$row->target_id] ?? '—');
        }

        return $rows;
    }

    // ─────────────────────── join 路徑（有帳號篩選／非 admin scope） ───────────────────────

    /** @return array{rows: array<int, object>, total: int, summary: array<string, array<string, int|string>>} */
    private function queryJoined(?string $scopePath, array $filters, string $identity, string $startUtc, string $endUtc, int $page, int $perPage): array
    {
        $parts = [];
        if ($identity !== 'member') {
            $parts[] = $this->agentSideQuery($scopePath, $filters, $startUtc, $endUtc);
        }
        if ($identity !== 'agent') {
            $parts[] = $this->memberSideQuery($scopePath, $filters, $startUtc, $endUtc);
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $outer = DB::query()->fromSub($union, 's');
        $total = (clone $outer)->count();

        $rows = $outer->select('s.*')
            ->orderBy('s.created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $rows = $this->attachWithdrawOrderNo($rows->all());

        return [
            'rows' => $rows,
            'total' => $total,
            'summary' => [
                'member' => $identity === 'agent'
                    ? $this->zeroSummary('member_count')
                    : $this->sideSummary($this->memberSideQuery($scopePath, $filters, $startUtc, $endUtc), 'member_count'),
                'agent' => $identity === 'member'
                    ? $this->zeroSummary('agent_count')
                    : $this->sideSummary($this->agentSideQuery($scopePath, $filters, $startUtc, $endUtc), 'agent_count'),
            ],
        ];
    }

    /** member 篩選非空時鎖定會員身分（搜會員帳號時代理列屬噪音，並使統計卡＝列表合計） */
    private function effectiveIdentity(array $filters): string
    {
        if ($filters['member'] !== '') {
            return 'member';
        }

        return $filters['identity'];
    }

    /** GMT+8 牆鐘 → UTC */
    private function toUtc(string $wallClock): string
    {
        return Carbon::parse($wallClock)->subHours(self::TZ_OFFSET_HOURS)->toDateTimeString();
    }

    /**
     * 會員受益列：先純表聚合（受益人 × 結算日，走 created_at 索引）再 join 名稱與範疇。
     * 代理商帳號篩選同時作用於會員列（＝該代理直屬下線）——舊版只作用代理列導致
     * 篩代理時統計卡混入全站會員（工單場景 C/D 失真），此為刻意修正。
     */
    private function memberSideQuery(?string $scopePath, array $filters, string $startUtc, string $endUtc): Builder
    {
        $inner = DB::table('three_hierarchy_bonus_report')
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<=', $endUtc)
            ->where('agent_id', 0)
            ->groupBy('summary_time', 'member_id')
            ->select(
                'summary_time',
                'member_id',
                DB::raw('SUM(rebate) as rebate'),
                DB::raw('SUM(down_rebate) as down_rebate'),
                DB::raw('SUM(down2_rebate) as down2_rebate'),
                DB::raw('SUM(bet + down_bet + down2_bet) as calc_base'),
                DB::raw('SUM(rebate_type = 2) as lose_rows'),
                DB::raw('MAX(created_at) as created_at'),
            );

        // fromSub 而非 DB::raw+mergeBindings：outer 的 join 帶綁定值時，後者的參數會錯位（bucket 順序）
        return DB::query()->fromSub($inner, 'l')
            ->join('members as m', function ($join) use ($filters) {
                $join->on('m.id', '=', 'l.member_id');
                if ($filters['member'] !== '') {
                    $join->where('m.username', $filters['member']);
                }
            })
            ->join('agents as a', function ($join) use ($scopePath, $filters) {
                $join->on('a.id', '=', 'm.parent');
                if ($scopePath !== null) {
                    $join->where('a.hierarchy_path', 'like', $scopePath . '%');
                }
                if ($filters['agent'] !== '') {
                    $join->where('a.username', $filters['agent']);
                }
            })
            ->select(
                DB::raw("'member' as identity"),
                'l.member_id as target_id',
                'm.username as username',
                'l.summary_time',
                'l.rebate',
                'l.down_rebate',
                'l.down2_rebate',
                DB::raw('(l.rebate + l.down_rebate + l.down2_rebate) as reward'),
                'l.calc_base',
                'l.lose_rows',
                'l.created_at',
            );
    }

    /** 代理受益列（member_id=0）：無提領流程，前端固定標「排程直接派發」 */
    private function agentSideQuery(?string $scopePath, array $filters, string $startUtc, string $endUtc): Builder
    {
        $inner = DB::table('three_hierarchy_bonus_report')
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<=', $endUtc)
            ->where('member_id', 0)
            ->groupBy('summary_time', 'agent_id')
            ->select(
                'summary_time',
                'agent_id',
                DB::raw('SUM(rebate) as rebate'),
                DB::raw('SUM(down_rebate) as down_rebate'),
                DB::raw('SUM(down2_rebate) as down2_rebate'),
                DB::raw('SUM(bet + down_bet + down2_bet) as calc_base'),
                DB::raw('SUM(rebate_type = 2) as lose_rows'),
                DB::raw('MAX(created_at) as created_at'),
            );

        return DB::query()->fromSub($inner, 'l')
            ->join('agents as a', function ($join) use ($scopePath, $filters) {
                $join->on('a.id', '=', 'l.agent_id');
                if ($scopePath !== null) {
                    $join->where('a.hierarchy_path', 'like', $scopePath . '%');
                }
                if ($filters['agent'] !== '') {
                    $join->where('a.username', $filters['agent']);
                }
            })
            ->select(
                DB::raw("'agent' as identity"),
                'l.agent_id as target_id',
                'a.username as username',
                'l.summary_time',
                'l.rebate',
                'l.down_rebate',
                'l.down2_rebate',
                DB::raw('(l.rebate + l.down_rebate + l.down2_rebate) as reward'),
                'l.calc_base',
                'l.lose_rows',
                'l.created_at',
            );
    }

    /**
     * 提現單號（工單場景 B）：對當頁會員列做一次批次配對（非逐列查詢）。
     * 配對規則＝同會員、收據時間 >= 該列派發時間的最早一筆（計算先於派發的時序保證）。
     *
     * @param  array<int, object>  $rows
     * @return array<int, object>
     */
    private function attachWithdrawOrderNo(array $rows): array
    {
        foreach ($rows as $row) {
            $row->withdraw_log_id = null;
        }

        $memberRows = array_filter($rows, fn (object $row) => $row->identity === 'member');
        if ($memberRows === []) {
            return $rows;
        }

        $memberIds = array_unique(array_map(fn (object $row) => (int) $row->target_id, $memberRows));
        $earliest = min(array_map(fn (object $row) => $row->created_at, $memberRows));

        // 收據依 (member_id, created_at) 升冪收集；匯出情境（per_page 大）分批 whereIn
        $logsByMember = [];
        foreach (array_chunk($memberIds, 1000) as $chunk) {
            ThreeHierarchyWithdrawLog::query()
                ->whereIn('member_id', $chunk)
                ->where('created_at', '>=', $earliest)
                ->orderBy('created_at')
                ->get(['id', 'member_id', 'created_at'])
                ->each(function (ThreeHierarchyWithdrawLog $log) use (&$logsByMember) {
                    $logsByMember[(int) $log->member_id][] = $log;
                });
        }

        // 「提現單號」在本系統 UI 語境＝withdraw_logs.id（提現紀錄頁的單號篩選實際比對 l.id）
        foreach ($rows as $row) {
            if ($row->identity !== 'member') {
                continue;
            }
            foreach ($logsByMember[(int) $row->target_id] ?? [] as $log) {
                if ($log->created_at->toDateTimeString() >= $row->created_at) {
                    $row->withdraw_log_id = (int) $log->id;
                    break;
                }
            }
        }

        return $rows;
    }

    /** 統計卡：與列表同一份查詢（同 join 同篩選）做 SUM＋去重人數，維持「卡片＝列表合計」 */
    private function sideSummary(Builder $sideQuery, string $countKey): array
    {
        $row = DB::query()->fromSub($sideQuery, 't')
            ->select(
                DB::raw('SUM(t.rebate) as rebate'),
                DB::raw('SUM(t.down_rebate) as down_rebate'),
                DB::raw('SUM(t.down2_rebate) as down2_rebate'),
                DB::raw('SUM(t.reward) as reward'),
                DB::raw('COUNT(DISTINCT t.target_id) as total'),
            )
            ->first();

        return [
            'rebate' => Common::numAdd('0', (string) ($row->rebate ?? 0), 4),
            'down_rebate' => Common::numAdd('0', (string) ($row->down_rebate ?? 0), 4),
            'down2_rebate' => Common::numAdd('0', (string) ($row->down2_rebate ?? 0), 4),
            'reward' => Common::numAdd('0', (string) ($row->reward ?? 0), 4),
            $countKey => (int) ($row->total ?? 0),
        ];
    }

    /** @return array<string, int|string> */
    private function zeroSummary(string $countKey): array
    {
        return [
            'rebate' => '0.0000',
            'down_rebate' => '0.0000',
            'down2_rebate' => '0.0000',
            'reward' => '0.0000',
            $countKey => 0,
        ];
    }

    /**
     * 下鑽明細（工單場景 F）：主表一列展開為源頭粒度，一列＝ bonus_report 的一列，
     * 與前台會員版 achievements() 同粒度。附全量合計與反水類別小計（防呆「混合」時前端顯示分類）。
     *
     * @return array{paginator: LengthAwarePaginator, summary: array<string, mixed>}
     */
    public function detail(Agent $agent, string $identity, int $targetId, string $summaryTime, int $page, int $perPage): array
    {
        $this->assertTargetInScope($agent, $identity, $targetId);

        $base = ThreeHierarchyBonusReport::query()
            ->where($identity === 'member' ? 'member_id' : 'agent_id', $targetId)
            ->where('summary_time', $summaryTime . ' 00:00:00');

        $paginator = (clone $base)
            ->with('fromMember:id,username', 'vendor:id,name', 'gameType:id,name')
            ->select(
                'id',
                'from_member_id',
                'vendor_id',
                'game_type_id',
                'rebate_type',
                'bet',
                'down_bet',
                'down2_bet',
                'rebate',
                'down_rebate',
                'down2_rebate',
            )
            ->orderBy('from_member_id')
            ->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);

        $totals = (clone $base)
            ->selectRaw('rebate_type')
            ->selectRaw('SUM(bet + down_bet + down2_bet) as base')
            ->selectRaw('SUM(rebate + down_rebate + down2_rebate) as reward')
            ->groupBy('rebate_type')
            ->get();

        $baseTotal = '0';
        $rewardTotal = '0';
        foreach ($totals as $row) {
            $baseTotal = Common::numAdd($baseTotal, (string) ($row->base ?? 0), 4);
            $rewardTotal = Common::numAdd($rewardTotal, (string) ($row->reward ?? 0), 4);
        }

        return [
            'paginator' => $paginator,
            'summary' => [
                'base' => $baseTotal,
                'reward' => $rewardTotal,
                'by_rebate_type' => $totals->map(fn (object $row) => [
                    'rebate_type' => (int) $row->rebate_type,
                    'base' => Common::numAdd('0', (string) $row->base, 4),
                    'reward' => Common::numAdd('0', (string) $row->reward, 4),
                ])->values()->all(),
            ],
        ];
    }

    /** 下鑽的越權防護：受益人必須在請求者可視範疇內（member 看其所屬代理、agent 看自身路徑） */
    private function assertTargetInScope(Agent $agent, string $identity, int $targetId): void
    {
        $scopePath = $this->scopePath($agent);
        if ($scopePath === null) {
            return;
        }

        if ($identity === 'member') {
            $inScope = Member::query()
                ->where('members.id', $targetId)
                ->join('agents as a', 'a.id', '=', 'members.parent')
                ->where('a.hierarchy_path', 'like', $scopePath . '%')
                ->exists();
        } else {
            $inScope = Agent::query()
                ->where('id', $targetId)
                ->where('hierarchy_path', 'like', $scopePath . '%')
                ->exists();
        }

        if (!$inScope) {
            abort(403, 'target out of scope');
        }
    }
}
