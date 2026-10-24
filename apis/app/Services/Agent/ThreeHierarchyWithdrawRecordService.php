<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Helpers\Common;
use App\Models\Agent;
use App\Services\Agent\Concerns\ResolvesAgentScopePath;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 三層代提現紀錄（4214，由 API_Agent `getWithdrawRecord` 移植並強化）。
 *
 * 資料源 three_hierarchy_withdraw_logs（返利入帳收據簿，一列＝一次實際入帳）：
 * 排程自動派發（admin=51）與會員前台申請（admin=0）兩個寫入點共用此表，
 * 「來源」欄以 admin 直譯（值域為兩寫入點的隱含約定，新增寫入點時須跟進）。
 *
 * 效能（TPower 5.4M 列、31 天窗 17.3 萬列實測，查詢 5 秒紅線；schema 不動、不用快取）：
 *  - join 版 count 冷查 17.6s＋聚合 3.9s，總帳 21.5s 超紅線 ⇒ admin 無帳號篩選走
 *    「快路徑」：count／取頁／統計聚合全純表（走 created_at 索引），只對當頁列補會員帳號
 *    ——join 從 17 萬次降到每頁筆數，總帳 21.5s → 約 2.6s，資料即時無快取延遲。
 *  - 有帳號篩選或非 admin scope 走 join 路徑（過濾後量小）。
 *  - 孤兒列（member 已刪的殘留收據）快路徑如實顯示、帳號「—」，不隱藏。
 *
 * 時區：DB 存 UTC，入參視為 GMT+8 牆鐘 -8h、出參 +8h（沿原版語意；
 * 前端本頁時間元件跟隨使用者時區，與派發紀錄固定 GMT+8 的不一致為工單已載明的保留項）。
 */
class ThreeHierarchyWithdrawRecordService
{
    use ResolvesAgentScopePath;

    private const TZ_OFFSET_HOURS = 8;

    /**
     * @param  array{agent: string, member: string, order_no: string, start_time: string, end_time: string}  $filters
     * @return array{rows: array<int, object>, total: int, summary: array<string, int|string>}
     */
    public function query(Agent $agent, array $filters, int $page, int $perPage): array
    {
        $scopePath = $this->scopePath($agent);
        // 快路徑條件：join 只為 scope／帳號篩選與補名——admin 無帳號篩選時全部可純表。
        // order_no 比對的是本表 id，純表就能過濾，不影響路徑選擇。
        $fast = $scopePath === null && $filters['agent'] === '' && $filters['member'] === '';

        return $fast
            ? $this->queryFast($filters, $page, $perPage)
            : $this->queryJoined($scopePath, $filters, $page, $perPage);
    }

    // ─────────────────────────── 快路徑（純表運算＋當頁補名） ───────────────────────────

    /** @return array{rows: array<int, object>, total: int, summary: array<string, int|string>} */
    private function queryFast(array $filters, int $page, int $perPage): array
    {
        $base = DB::table('three_hierarchy_withdraw_logs')
            ->where('created_at', '>=', $this->toUtc($filters['start_time']))
            ->where('created_at', '<=', $this->toUtc($filters['end_time']))
            ->when($filters['order_no'] !== '', function ($query) use ($filters) {
                $query->where('id', $filters['order_no']);
            });

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get([
                'id', 'order_no', 'member_id', 'type', 'admin',
                'withdraw_point', 'remaining_point', 'status', 'created_at',
            ]);

        $summary = $this->buildSummary(
            (clone $base)->select(
                DB::raw('SUM(withdraw_point) as total_point'),
                DB::raw('COUNT(*) as total_count'),
                DB::raw('COUNT(DISTINCT member_id) as member_count'),
            )->first(),
        );

        return [
            'rows' => $this->attachUsernames($rows),
            'total' => $total,
            'summary' => $summary,
        ];
    }

    /**
     * 只對當頁列補會員帳號（join 從 17 萬次降到每頁筆數），並將時間轉 GMT+8。
     * 孤兒列（member 已刪的殘留收據）帳號顯示「—」，如實呈現不隱藏。
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, object>
     */
    private function attachUsernames(Collection $rows): array
    {
        $usernames = DB::table('members')
            ->whereIn('id', $rows->pluck('member_id')->unique()->values())
            ->pluck('username', 'id');

        foreach ($rows as $row) {
            $row->username = $usernames[$row->member_id] ?? '—';
            $row->created_at = Carbon::parse($row->created_at)->addHours(self::TZ_OFFSET_HOURS)->toDateTimeString();
            unset($row->member_id);
        }

        return $rows->all();
    }

    // ─────────────────────────── join 路徑（有帳號篩選／非 admin scope） ───────────────────────────

    /** @return array{rows: array<int, object>, total: int, summary: array<string, int|string>} */
    private function queryJoined(?string $scopePath, array $filters, int $page, int $perPage): array
    {
        $base = $this->baseQuery($scopePath, $filters);

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->orderBy('l.created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->select(
                'l.id',
                'l.order_no',
                'm.username',
                'l.type',
                'l.admin',
                'l.withdraw_point',
                'l.remaining_point',
                'l.status',
                DB::raw('DATE_ADD(l.created_at, INTERVAL ' . self::TZ_OFFSET_HOURS . ' HOUR) as created_at'),
            )
            ->get()
            ->all();

        $summary = $this->buildSummary(
            (clone $base)->select(
                DB::raw('SUM(l.withdraw_point) as total_point'),
                DB::raw('COUNT(*) as total_count'),
                DB::raw('COUNT(DISTINCT l.member_id) as member_count'),
            )->first(),
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'summary' => $summary,
        ];
    }

    /**
     * join 主查詢：沿原版結構；代理商帳號篩選為 4214 新增（join agents 本來就在，只加條件）。
     */
    private function baseQuery(?string $scopePath, array $filters): Builder
    {
        return DB::table('three_hierarchy_withdraw_logs as l')
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
            ->where('l.created_at', '>=', $this->toUtc($filters['start_time']))
            ->where('l.created_at', '<=', $this->toUtc($filters['end_time']))
            ->when($filters['order_no'] !== '', function ($query) use ($filters) {
                // 沿舊接口語意：參數名 order_no、實際比對 log 的 id（UI 全體系的「提現單號」）
                $query->where('l.id', $filters['order_no']);
            });
    }

    /** @return array<string, int|string> */
    private function buildSummary(?object $row): array
    {
        return [
            'total_point' => Common::numAdd('0', (string) ($row->total_point ?? 0), 2),
            'total_count' => (int) ($row->total_count ?? 0),
            'member_count' => (int) ($row->member_count ?? 0),
        ];
    }

    /** GMT+8 牆鐘 → UTC */
    private function toUtc(string $wallClock): string
    {
        return Carbon::parse($wallClock)->subHours(self::TZ_OFFSET_HOURS)->toDateTimeString();
    }
}
