<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\RoleEnum;
use App\Enums\General\CacheTtl;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\SummaryBetRecord;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 站點儀表板 工單4155
 *
 * 與 4180 代理數據看板平行的另一個視角：4180 以「代理」為中心往下鑽，
 * 本儀表板以「總代（站點）」為切割——admin 可挑任一總代或 ALL 全站加總，
 * 其他人（含總代自己）鎖定自己所屬的那條總代線。
 *
 * 資料口徑（沿 4180 定案，見 AgentDashboardService）：
 *   充值／提款   → member_hourly_statistics（成功狀態已固化）
 *   投注／輸贏   → summary_bet_records（排除 custom.report.excluded_vendor_ids 的遊戲商，對齊 SR11）；
 *                 win_lose 為會員淨輸贏 ⇒ 公司角度總輸贏 = −win_lose
 *   反水及活動獎勵 → summary_event_records，event_id ∈ {返水6, 活動7}
 *
 * 一律日結 D-1：查詢迄日最多到「前端時區的昨天」，今日資料不出現。
 * 因 D-1 資料不再變動，整包結果快取一小時，重複開頁不重算。
 */
class SiteDashboardService
{
    /** 反水及活動獎勵的成本分桶（沿 4180 的 event_id 定義） */
    private const array REWARD_EVENT_IDS = [6, 7];

    /**
     * 站點按鈕清單
     *
     * admin 回 ALL＋全部總代；其他人回空——非 admin 只能看自己以下的線，
     * 介面不顯示任何站點按鈕（需求調整：代理身分不再共用整條總代線）。
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function sites(Agent $viewer): array
    {
        if ($this->isAdmin($viewer)) {
            // 總代＝「總代理」角色，不是樹位置——總代可巢狀（總代→總代→代理，
            // 如 VWorld 的 Acompany 掛在 vworldmy 線下），故不能用 parent=1 圈
            $tops = DB::table('agents')
                ->where('role_id', RoleEnum::GENERAL_AGENT->value)
                ->where('host_id', 0) // 子帳號不是站點
                ->where('status', 1)
                ->orderBy('id')
                ->get(['id', 'username', 'nickname']);

            // 顯示名一律用帳號（username），不用暱稱——營運以帳號辨識站點
            return collect([['id' => 0, 'name' => 'ALL']])
                ->concat($tops->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->username,
                ]))
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * 數字卡：期間新註冊、玩家總人數（當下）、代理總人數（當下）
     *
     * @param  array{site: int|string, start_time: string, end_time: string, tz_offset: int}  $input
     * @return array{new_members: int, total_members: int, total_agents: int}
     */
    public function summary(Agent $viewer, array $input): array
    {
        $scope = $this->resolveScope($viewer, (int) $input['site']);
        [$startAt, $endAt] = $this->range($input);

        $cacheKey = CacheKeys::siteDashboard('summary', $scope['key'], $startAt, $endAt);

        return Cache::remember($cacheKey, CacheTtl::ONE_DAY->value, function () use ($scope, $startAt, $endAt) {
            return [
                'new_members' => (int) $this->scopedMembers($scope)
                    ->whereBetween('m.created_at', [$startAt, $endAt])
                    ->count(),
                // 玩家／代理總人數為「當下」總量，不隨日期區間變動
                'total_members' => (int) $this->scopedMembers($scope)->count(),
                'total_agents' => (int) $this->scopedAgents($scope)->count(),
            ];
        });
    }

    /**
     * 三張圖的日序列（一次回，前端各自取用）
     *
     * 缺資料的日期補零，序列長度固定等於查詢天數——圖表軸線才不會缺格。
     *
     * @param  array{site: int|string, start_time: string, end_time: string, tz_offset: int}  $input
     * @return array{dates: array<int, string>, registrations: array<int, int>, deposit: array<int, string>, withdrawal: array<int, string>, bet: array<int, string>, company_win: array<int, string>, rewards: array<int, string>}
     */
    public function trends(Agent $viewer, array $input): array
    {
        $scope = $this->resolveScope($viewer, (int) $input['site']);
        [$startAt, $endAt] = $this->range($input);
        $timezone = $this->timezoneOf((int) $input['tz_offset']);

        $cacheKey = CacheKeys::siteDashboard('trends', $scope['key'], $startAt, $endAt);

        return Cache::remember($cacheKey, CacheTtl::ONE_DAY->value, function () use ($scope, $startAt, $endAt, $timezone) {
            $dates = $this->dateAxis($startAt, $endAt, $timezone);
            $day = fn (string $column) => DB::raw(
                "DATE(CONVERT_TZ({$column}, '+00:00', '{$timezone}')) as d"
            );

            // 新註冊／日
            $registrations = $this->scopedMembers($scope)
                ->whereBetween('m.created_at', [$startAt, $endAt])
                ->groupBy('d')
                ->select($day('m.created_at'))
                ->selectRaw('COUNT(*) as c')
                ->pluck('c', 'd');

            // 充值／提款／日（整點桶，起點已對齊整點）
            $hourly = $this->scopedMembers($scope, 'member_hourly_statistics as mhs', 'mhs.member_id')
                ->whereBetween('mhs.summary_hour', [$startAt, $endAt])
                ->groupBy('d')
                ->select($day('mhs.summary_hour'))
                ->selectRaw('SUM(mhs.deposit_amount) as dep, SUM(mhs.withdrawal_amount) as wd')
                ->get()
                ->keyBy('d');

            // 投注／公司輸贏／日
            $bets = $this->scopedMembers($scope, 'summary_bet_records as sbr', 'sbr.member_id')
                ->whereBetween('sbr.summary_time', [$startAt, $endAt])
                ->whereNotIn('sbr.vendor_id', SummaryBetRecord::reportExcludedVendorIds())
                ->groupBy('d')
                ->select($day('sbr.summary_time'))
                ->selectRaw('SUM(sbr.bet) as bet, SUM(sbr.win_lose) as win_lose')
                ->get()
                ->keyBy('d');

            // 反水及活動獎勵／日
            $rewards = $this->scopedMembers($scope, 'summary_event_records as ser', 'ser.member_id')
                ->whereIn('ser.event_id', self::REWARD_EVENT_IDS)
                ->whereBetween('ser.summary_time', [$startAt, $endAt])
                ->groupBy('d')
                ->select($day('ser.summary_time'))
                ->selectRaw('SUM(ser.point) as p')
                ->pluck('p', 'd');

            return [
                'dates' => $dates,
                'registrations' => array_map(fn ($d) => (int) ($registrations[$d] ?? 0), $dates),
                'deposit' => array_map(fn ($d) => (string) ($hourly[$d]->dep ?? '0'), $dates),
                'withdrawal' => array_map(fn ($d) => (string) ($hourly[$d]->wd ?? '0'), $dates),
                'bet' => array_map(fn ($d) => (string) ($bets[$d]->bet ?? '0'), $dates),
                // win_lose 為會員淨輸贏，公司角度取負值（沿 4180 GGR 口徑）
                'company_win' => array_map(fn ($d) => isset($bets[$d]) ? Common::numMul((string) $bets[$d]->win_lose, '-1', 4) : '0', $dates),
                'rewards' => array_map(fn ($d) => (string) ($rewards[$d] ?? '0'), $dates),
            ];
        });
    }

    /**
     * 站點範圍解析
     *
     * admin：site=0 為 ALL（不加代理過濾）、或指定總代 id；
     * 其他人：無視傳入值，一律鎖「自己以下」（子帳號歸戶到主帳號）——
     * 防止改參數看別條線或上層；B 代只看得到 B 自己與其下線的統計。
     *
     * @return array{key: string, top: object|null}
     */
    private function resolveScope(Agent $viewer, int $siteId): array
    {
        if ($this->isAdmin($viewer)) {
            if ($siteId === 0) {
                return ['key' => 'all', 'top' => null];
            }

            $top = DB::table('agents')
                ->where('id', $siteId)
                ->where('role_id', RoleEnum::GENERAL_AGENT->value)
                ->where('host_id', 0) // 與 sites() 同口徑：子帳號不是站點
                ->first(['id', 'hierarchy_path']);

            if (!$top) {
                throw new ApiException(__('error_message.9991'));
            }

            return ['key' => "top:{$top->id}", 'top' => $top];
        }

        $own = $this->lineOwnerOf($viewer);

        // key 用 own: 前綴與舊口徑（top:）區隔，避免沿用調整前的快取
        return ['key' => "own:{$own->id}", 'top' => $own];
    }

    /**
     * members 基底查詢（含站點範圍過濾）
     *
     * ALL 時完全不 join agents——省掉無意義的關聯成本；
     * 指定總代時以 hierarchy_path 前綴圈線（agents 僅數萬列、join 成本可控），
     * 避免先撈整條線的代理 id 再塞進巨大 IN 清單。
     */
    private function scopedMembers(array $scope, ?string $from = null, ?string $memberColumn = null): Builder
    {
        // ALL：完全不 join——實測 92 天投注查詢帶 members join 63 秒、去掉 4 秒，
        // join 是全站聚合的頭號成本，非必要絕不掛
        if ($scope['top'] === null) {
            return $from === null ? DB::table('members as m') : DB::table($from);
        }

        $top = $scope['top'];

        $query = $from === null
            ? DB::table('members as m')
            : DB::table($from)->join('members as m', 'm.id', '=', $memberColumn);

        return $query->join('agents as a', 'a.id', '=', 'm.parent')
            ->where(function ($where) use ($top) {
                // 兩段式比對：$path . '/%' 避免 /1/23 誤匹配 /1/234
                $where->where('a.id', $top->id)
                    ->orWhere('a.hierarchy_path', 'like', $top->hierarchy_path . '/%');
            });
    }

    /** agents 基底查詢（含站點範圍過濾），供代理總人數卡 */
    private function scopedAgents(array $scope): Builder
    {
        $query = DB::table('agents')->where('status', 1);

        if ($scope['top'] === null) {
            return $query;
        }

        $top = $scope['top'];

        return $query->where(function ($where) use ($top) {
            // 兩段式比對：$path . '/%' 避免 /1/23 誤匹配 /1/234
            $where->where('id', $top->id)
                ->orWhere('hierarchy_path', 'like', $top->hierarchy_path . '/%');
        });
    }

    /** 觀看者自己的線（子帳號歸戶到主帳號）——非 admin 只能看自己以下 */
    private function lineOwnerOf(Agent $viewer): object
    {
        $ownerId = $viewer->host_id != 0 ? (int) $viewer->host_id : (int) $viewer->id;

        $owner = DB::table('agents')->where('id', $ownerId)->first(['id', 'username', 'nickname', 'hierarchy_path']);

        if (!$owner) {
            throw new ApiException(__('error_message.9991'));
        }

        return $owner;
    }

    /**
     * 查詢區間：前端時區牆鐘 ⇒ UTC，並以 D-1 封頂
     *
     * 迄時最多到「前端時區的昨天 23:59:59」——日結儀表板不顯示今日的半成品資料。
     *
     * @return array{0: string, 1: string} [起, 迄]（UTC）
     */
    private function range(array $input): array
    {
        $timezone = $this->timezoneOf((int) $input['tz_offset']);

        $start = Carbon::parse($input['start_time'], $timezone);
        $end = Carbon::parse($input['end_time'], $timezone);
        $cap = Carbon::now($timezone)->startOfDay()->subSecond();

        if ($end->greaterThan($cap)) {
            $end = $cap;
        }

        if ($start->greaterThan($end)) {
            $start = $end->copy()->startOfDay();
        }

        return [
            $start->utc()->format('Y-m-d H:i:s'),
            $end->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** 偏移小時 ⇒ `+08:00` 形式（沿 4180） */
    private function timezoneOf(int $offsetHours): string
    {
        return sprintf('%s%02d:00', $offsetHours < 0 ? '-' : '+', abs($offsetHours));
    }

    /**
     * 日期軸（前端時區的每一天），序列補零的基準
     *
     * @return array<int, string> Y-m-d
     */
    private function dateAxis(string $startAtUtc, string $endAtUtc, string $timezone): array
    {
        $cursor = Carbon::parse($startAtUtc, 'UTC')->setTimezone($timezone)->startOfDay();
        $last = Carbon::parse($endAtUtc, 'UTC')->setTimezone($timezone)->startOfDay();

        $dates = [];
        while ($cursor->lessThanOrEqualTo($last)) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $dates;
    }

    private function isAdmin(Agent $agent): bool
    {
        return $agent->id == 1 || $agent->host_id == 1;
    }
}
