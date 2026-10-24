<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\General\CacheTtl;
use App\Helpers\Common;
use App\Models\Agent;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 三層代用戶查詢（4190，由 API_Agent `getUser` 移植並強化）。
 *
 * 一列＝一個返利受益人：會員列來自 three_hierarchy_summary_bonus、
 * 代理列來自 three_hierarchy_summary_bonus_agents，unionAll 後子查詢分頁
 * （複雜 union 聚合無對應單一 Model，比照 3967 會員活動篩選器以 query builder 實作）。
 *
 * 業務口徑（沿 API_Agent 原版）：
 *  - member 篩選非空 ⇒ 只查會員列（覆寫 identity）。
 *  - 代理無提領概念（排程直接派發錢包），withdraw 欄回佔位 '_'；
 *    依 withdraw_able / withdraw_amount 排序時自動限會員身分，避免佔位字串亂序。
 *  - 統計卡與列表同一組篩選條件；identity 篩掉的那組回零，維持「卡片＝列表合計」。
 *
 * 效能（TPower 21 萬列實測，查詢 5 秒紅線）：
 *  - 舊寫法（全量 join 後 count/聚合/分頁共 4 趟）端到端 7~11 秒 ⇒ 改「快路徑」：
 *    admin 無帳號篩選時 count/排序/分頁/聚合全走純表（join 從 21 萬次降到當頁 20 次），
 *    9.3s → 約 1.5s；join 只為補顯示欄位，孤兒列（member/agent 已刪的殘留累計）
 *    如實顯示、帳號欄「—」，「卡片＝列表」恆等不變。
 *  - 有帳號篩選或非 admin scope 走 join 路徑（過濾後量小，本來就快）。
 *  - summary 另有 safeRemember 180 秒兜底重複查詢。
 */
class ThreeHierarchyUserSearchService
{
    /** 排序時必須鎖定會員身分的欄位（代理列該欄為佔位字串） */
    private const MEMBER_ONLY_SORTS = ['withdraw_able', 'withdraw_amount'];

    /**
     * @param  array{agent: string, member: string, identity: string, sort: string, direction: string}  $filters
     * @return array{paginator: LengthAwarePaginator, summary: array<string, array<string, int|string>>}
     */
    public function query(Agent $agent, array $filters, int $page, int $perPage): array
    {
        $scopePath = $this->scopePath($agent);
        $identity = $this->effectiveIdentity($filters);
        $fast = $this->canUseFastPath($scopePath, $filters);

        return [
            'paginator' => $fast
                ? $this->searchFast($filters, $identity, $page, $perPage)
                : $this->search($scopePath, $filters, $identity, $page, $perPage),
            'summary' => $this->summary($scopePath, $filters, $identity, $fast),
        ];
    }

    /**
     * 快路徑條件：admin（不限範疇）且無帳號篩選——count／排序／分頁／聚合全部
     * 不需要 join（join 只為了帳號名等顯示欄位）。created_at 排序例外：
     * 顯示的建立時間來自 members/agents 表，純表排不了，退回 join 路徑。
     */
    private function canUseFastPath(?string $scopePath, array $filters): bool
    {
        return $scopePath === null
            && $filters['agent'] === ''
            && $filters['member'] === ''
            && $filters['sort'] !== 'created_at';
    }

    /**
     * 取得可視範疇的 hierarchy_path 前綴；admin(id=1)與其子帳號(host_id=1)回 null 表不限制。
     * 代理子帳號(host_id!=0)看其上層代理的範疇（比照 MemberActivityFilterService）。
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

    /** member 篩選非空與 withdraw 類排序都會把身分鎖定為會員（優先於 identity 參數） */
    private function effectiveIdentity(array $filters): string
    {
        if ($filters['member'] !== '' || in_array($filters['sort'], self::MEMBER_ONLY_SORTS, true)) {
            return 'member';
        }

        return $filters['identity'];
    }

    // ─────────────────────────── 快路徑（純表運算＋當頁補名） ───────────────────────────

    private function searchFast(array $filters, string $identity, int $page, int $perPage): LengthAwarePaginator
    {
        // count 不跑 union：兩表各自 COUNT（TPower 實測 0.04s vs union join count 1.7s）
        $total = 0;
        if ($identity !== 'member') {
            $total += (int) DB::table('three_hierarchy_summary_bonus_agents')->count();
        }
        if ($identity !== 'agent') {
            $total += (int) DB::table('three_hierarchy_summary_bonus')->count();
        }

        $parts = [];
        // 順序沿原版：代理列在前、會員列在後（無排序時的天然順序）
        if ($identity !== 'member') {
            $parts[] = $this->agentSideFastQuery();
        }
        if ($identity !== 'agent') {
            $parts[] = $this->memberSideFastQuery();
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $rows = DB::query()->fromSub($union, 's')
            ->select('s.*')
            ->when($filters['sort'] !== '', function ($query) use ($filters) {
                $query->orderBy('s.' . $filters['sort'], $filters['direction'] === 'asc' ? 'asc' : 'desc');
            })
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return new LengthAwarePaginator($this->attachDisplayFields($rows), $total, $perPage, $page);
    }

    /** 會員列（純表）：ref_id 供補名；created_at 為佔位，補名時以 members.created_at 覆蓋 */
    private function memberSideFastQuery(): Builder
    {
        return DB::table('three_hierarchy_summary_bonus')
            ->select(
                DB::raw("'member' as identity"),
                'member_id as ref_id',
                'rebate_amount',
                'down_rebate_amount',
                'down2_rebate_amount',
                'bonus_amount',
                DB::raw('(bonus_amount - withdraw_amount) as withdraw_able'),
                'withdraw_amount',
                'created_at',
            );
    }

    /** 代理列（純表） */
    private function agentSideFastQuery(): Builder
    {
        return DB::table('three_hierarchy_summary_bonus_agents')
            ->select(
                DB::raw("'agent' as identity"),
                'agent_id as ref_id',
                'rebate_amount',
                'down_rebate_amount',
                'down2_rebate_amount',
                'bonus_amount',
                DB::raw("'_' as withdraw_able"),
                DB::raw("'_' as withdraw_amount"),
                'created_at',
            );
    }

    /**
     * 只對當頁列補顯示欄位（帳號名、建立時間）——join 從全表 N 萬次降到每頁筆數。
     * 孤兒列（member/agent 已刪的殘留累計）帳號顯示「—」，如實呈現不隱藏。
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, object>
     */
    private function attachDisplayFields(Collection $rows): array
    {
        $memberIds = $rows->where('identity', 'member')->pluck('ref_id')->unique()->values();
        $agentRefIds = $rows->where('identity', 'agent')->pluck('ref_id')->unique()->values();

        $members = DB::table('members')
            ->whereIn('id', $memberIds)
            ->get(['id', 'username', 'parent', 'created_at'])
            ->keyBy('id');

        $agentIds = $members->pluck('parent')->merge($agentRefIds)->unique()->values();
        $agents = DB::table('agents')
            ->whereIn('id', $agentIds)
            ->get(['id', 'username', 'created_at'])
            ->keyBy('id');

        foreach ($rows as $row) {
            if ($row->identity === 'member') {
                $member = $members->get($row->ref_id);
                $row->member_username = $member->username ?? '—';
                $row->parent_username = $member !== null
                    ? ($agents->get($member->parent)->username ?? '—')
                    : '—';
                $row->created_at = $member->created_at ?? $row->created_at;
            } else {
                $agentRow = $agents->get($row->ref_id);
                $row->parent_username = $agentRow->username ?? '—';
                $row->member_username = '_';
                $row->created_at = $agentRow->created_at ?? $row->created_at;
            }
            unset($row->ref_id);
        }

        return $rows->all();
    }

    // ─────────────────────────── join 路徑（有篩選／非 admin scope） ───────────────────────────

    private function search(?string $scopePath, array $filters, string $identity, int $page, int $perPage): LengthAwarePaginator
    {
        $parts = [];
        // 順序沿原版：代理列在前、會員列在後（無排序時的天然順序）
        if ($identity !== 'member') {
            $parts[] = $this->agentSideQuery($scopePath, $filters);
        }
        if ($identity !== 'agent') {
            $parts[] = $this->memberSideQuery($scopePath, $filters);
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $outer = DB::table(DB::raw("({$union->toSql()}) as s"))->mergeBindings($union);
        $total = (clone $outer)->count();

        $rows = $outer->select('s.*')
            ->when($filters['sort'] !== '', function ($query) use ($filters) {
                $query->orderBy('s.' . $filters['sort'], $filters['direction'] === 'asc' ? 'asc' : 'desc');
            })
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /** 會員列：受益人是會員，withdraw_able = 累計獎金 − 已提領（前台自提現） */
    private function memberSideQuery(?string $scopePath, array $filters): Builder
    {
        return DB::table('three_hierarchy_summary_bonus as t')
            ->join('members as m', function ($join) use ($filters) {
                $join->on('m.id', '=', 't.member_id');
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
                'a.username as parent_username',
                'm.username as member_username',
                't.rebate_amount as rebate_amount',
                't.down_rebate_amount as down_rebate_amount',
                't.down2_rebate_amount as down2_rebate_amount',
                't.bonus_amount as bonus_amount',
                DB::raw('(t.bonus_amount - t.withdraw_amount) as withdraw_able'),
                't.withdraw_amount as withdraw_amount',
                'm.created_at',
            );
    }

    /** 代理列：排程直接派發錢包，無提領概念，withdraw 欄回佔位 '_'（沿原版口徑） */
    private function agentSideQuery(?string $scopePath, array $filters): Builder
    {
        return DB::table('three_hierarchy_summary_bonus_agents as t')
            ->join('agents as a', function ($join) use ($scopePath, $filters) {
                $join->on('a.id', '=', 't.agent_id');
                if ($scopePath !== null) {
                    $join->where('a.hierarchy_path', 'like', $scopePath . '%');
                }
                if ($filters['agent'] !== '') {
                    $join->where('a.username', $filters['agent']);
                }
            })
            ->select(
                DB::raw("'agent' as identity"),
                'a.username as parent_username',
                DB::raw("'_' as member_username"),
                't.rebate_amount as rebate_amount',
                't.down_rebate_amount as down_rebate_amount',
                't.down2_rebate_amount as down2_rebate_amount',
                't.bonus_amount as bonus_amount',
                DB::raw("'_' as withdraw_able"),
                DB::raw("'_' as withdraw_amount"),
                'a.created_at',
            );
    }

    // ─────────────────────────── 統計卡 ───────────────────────────

    /**
     * 兩組統計卡（會員合計／代理合計），與列表同一組篩選條件、同一種路徑
     * （快路徑純表聚合、join 路徑同 join——維持「卡片＝列表合計」）。
     *
     * D 級變動頻率（累計表每日排程更新）＋高成本聚合 ⇒ safeRemember 180 秒。
     *
     * @return array<string, array<string, int|string>>
     */
    private function summary(?string $scopePath, array $filters, string $identity, bool $fast): array
    {
        $cacheKey = sprintf(
            'three_hierarchy:user_search:summary:%s',
            md5(($scopePath ?? 'ALL') . '|' . $filters['agent'] . '|' . $filters['member'] . '|' . $identity),
        );

        return Cache::safeRemember($cacheKey, CacheTtl::JIFFY->value, function () use ($scopePath, $filters, $identity, $fast) {
            return [
                'member' => $identity === 'agent'
                    ? $this->zeroSummary(['bonus_amount', 'withdraw_amount', 'withdraw_able'])
                    : $this->memberSummary($scopePath, $filters, $fast),
                'agent' => $identity === 'member'
                    ? $this->zeroSummary(['down_rebate_amount', 'down2_rebate_amount', 'bonus_amount'])
                    : $this->agentSummary($scopePath, $filters, $fast),
            ];
        });
    }

    /** @return array<string, int|string> */
    private function memberSummary(?string $scopePath, array $filters, bool $fast): array
    {
        // 快路徑純表聚合（0.14s）；join 路徑與列表同一份 join——兩種路徑各自「卡片＝列表」恆等
        $base = $fast
            ? DB::table('three_hierarchy_summary_bonus as t')
            : $this->memberSideQuery($scopePath, $filters);

        $row = $base
            ->select(
                DB::raw('SUM(t.bonus_amount) as bonus_amount'),
                DB::raw('SUM(t.withdraw_amount) as withdraw_amount'),
                DB::raw('SUM(t.bonus_amount - t.withdraw_amount) as withdraw_able'),
                DB::raw('COUNT(*) as total'),
            )
            ->first();

        return [
            'bonus_amount' => Common::numAdd('0', (string) ($row->bonus_amount ?? 0), 4),
            'withdraw_amount' => Common::numAdd('0', (string) ($row->withdraw_amount ?? 0), 4),
            'withdraw_able' => Common::numAdd('0', (string) ($row->withdraw_able ?? 0), 4),
            'total' => (int) ($row->total ?? 0),
        ];
    }

    /** @return array<string, int|string> */
    private function agentSummary(?string $scopePath, array $filters, bool $fast): array
    {
        $base = $fast
            ? DB::table('three_hierarchy_summary_bonus_agents as t')
            : $this->agentSideQuery($scopePath, $filters);

        $row = $base
            ->select(
                DB::raw('SUM(t.down_rebate_amount) as down_rebate_amount'),
                DB::raw('SUM(t.down2_rebate_amount) as down2_rebate_amount'),
                DB::raw('SUM(t.bonus_amount) as bonus_amount'),
                DB::raw('COUNT(*) as total'),
            )
            ->first();

        return [
            'down_rebate_amount' => Common::numAdd('0', (string) ($row->down_rebate_amount ?? 0), 4),
            'down2_rebate_amount' => Common::numAdd('0', (string) ($row->down2_rebate_amount ?? 0), 4),
            'bonus_amount' => Common::numAdd('0', (string) ($row->bonus_amount ?? 0), 4),
            'total' => (int) ($row->total ?? 0),
        ];
    }

    /** @return array<string, int|string> */
    private function zeroSummary(array $amountKeys): array
    {
        $zero = array_fill_keys($amountKeys, '0.0000');
        $zero['total'] = 0;

        return $zero;
    }
}
