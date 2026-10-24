<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Data\Ranking\RankingFakeData;
use App\Data\Ranking\RankingFakeRecordData;
use App\Data\Ranking\RankingGameWinnerData;
use App\Data\Ranking\RankingProfitData;
use App\Enums\General\CacheTtl;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\VendorCustomizedItem;
use App\Models\VendorHourRecord;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RankingService
{
    /** 中獎排行榜／平台投注排行榜的最低達標門檻（舊版沿用至今的固定值，未做成可設定項） */
    private const string MIN_THRESHOLD = '500';

    /**
     * 代理層級篩選：自己或其任何下線
     *
     * `LIKE '{path}%'`（無分隔符）會誤配到同層級 id 前綴相同的兄弟代理（例如 path `/1/715`
     * 會誤配到 `/1/7151`）；改為「等於自己」或「以 `{path}/` 為前綴」，與 AgentController／
     * MarqueeController 既有寫法對齊。
     */
    private function hierarchyPathScope(string $column, Agent $agent): Closure
    {
        return fn ($query) => $query->where($column, $agent->hierarchy_path)
            ->orWhere($column, 'like', $agent->hierarchy_path . '/%');
    }

    /**
     * @return Collection<RankingGameWinnerData>
     */
    public function getGameWinners(Agent $agent, string $startTime, string $endTime): Collection
    {
        $key = CacheKeys::gameWinnerList($startTime, $agent->id);

        $data = Cache::safeRemember($key, CacheTtl::ONE_HOUR->value, function () use ($agent, $startTime, $endTime) {
            $vendorHourRecords = VendorHourRecord::query()
                ->where('date_hour', '>=', $startTime)
                ->where('date_hour', '<', $endTime)
                ->whereHas('vendor', fn ($query) => $query->where('is_free', 0))
                ->has('member')
                ->whereHas('member.agent', function ($query) use ($agent) {
                    $query->where($this->hierarchyPathScope('hierarchy_path', $agent));
                })
                ->with([
                    'member:id,username,image',
                    'vendor:id,name',
                    'vendorCustomizedItems' => function ($query) use ($agent) {
                        $query->where('agent_group_id', $agent->vendor_customize_group_id)
                            ->where('item_type', 1)
                            ->where('status', 1)
                            ->select('id', 'name', 'vendor_id');
                    },
                ])
                ->select([
                    // 'id',
                    'member_id',
                    'vendor_id',
                    DB::raw('SUM(win_lose) as total_win_lose'),
                ])
                ->orderByDesc('total_win_lose')
                ->groupBy(['member_id', 'vendor_id'])
                ->havingRaw('SUM(win_lose) >= 1000')
                ->limit(30)
                ->get();

            $data = $vendorHourRecords->map(fn ($item) => RankingGameWinnerData::from([
                'username' => Common::maskString($item->member->username),
                'image' => $item->member->image,
                'total_win_lose' => $item->total_win_lose,
                'vendor_name' => $item->vendorCustomizedItems->first()->name ?? $item->vendor->name,
            ]));

            return $data;
        });

        return $data;
    }

    /**
     * @return Collection<RankingProfitData>
     */
    public function getProfits(Agent $agent, string $startTime, string $endTime): Collection
    {
        $key = CacheKeys::getProfitList($startTime, $agent->id);

        $data = Cache::safeRemember($key, CacheTtl::ONE_HOUR->value, function () use ($agent, $startTime, $endTime) {
            $vendorHourRecords = VendorHourRecord::query()
                ->where('date_hour', '>=', $startTime)
                ->where('date_hour', '<=', $endTime)
                ->whereHas('vendor', fn ($query) => $query->where('is_free', 0))
                ->has('member')
                ->whereHas('member.agent', function ($query) use ($agent) {
                    $query->where($this->hierarchyPathScope('hierarchy_path', $agent));
                })
                ->with('member:id,username,image')
                ->select(
                    'member_id',
                    DB::raw('SUM(win_lose) as total_win_lose'),
                )
                ->orderByDesc('total_win_lose')
                ->groupBy('member_id')
                ->havingRaw('SUM(win_lose) >= 1000')
                ->limit(30)
                ->get();

            $data = $vendorHourRecords->map(fn ($item) => RankingProfitData::from([
                'username' => Common::maskString($item->member->username),
                'image' => $item->member->image,
                'total_win_lose' => $item->total_win_lose,
            ]));

            return $data;
        });

        return $data;
    }

    /**
     * 中獎排行榜（`ranking/winning`）
     *
     * tpower-master 與 vw-master 逐行完全相同。type=1（即時）不套時間篩選、依派彩時間排序；
     * 2/3/4（日/週/月）套對應區間、依輸贏金額排序。未提供排名欄位（沿用舊版行為）。
     *
     * @return Collection<int, mixed>
     */
    public function getWinningRanking(Agent $agent, int $type, int $languageId): Collection
    {
        $key = CacheKeys::winningRankingList($agent->id, $type, $languageId);

        return Cache::safeRemember($key, CacheTtl::DEFAULT->value, function () use ($agent, $type, $languageId) {
            [$startTime, $endTime] = $this->resolveWinningRankingRange($type);

            $query = DB::table('ranking_tbr_records as rtr')
                ->join('members as m', 'm.id', 'rtr.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->join('games as g', 'g.id', 'rtr.game_id')
                ->leftJoin('game_names as gn', function ($join) use ($languageId) {
                    $join->on('gn.game_id', 'g.id')->where('gn.language_id', $languageId);
                })
                ->join('vendors as v', 'v.id', 'rtr.vendor_id')
                ->leftJoin('vendor_customized_items as vci', function ($join) use ($agent) {
                    $join->on('vci.vendor_id', 'v.id')
                        ->where('vci.agent_group_id', $agent->vendor_customize_group_id)
                        ->where('vci.item_type', 1)
                        ->where('vci.status', 1);
                })
                ->where('rtr.win_lose', '>=', self::MIN_THRESHOLD)
                ->where($this->hierarchyPathScope('a.hierarchy_path', $agent))
                ->when($startTime !== null, fn ($q) => $q
                    ->where('rtr.payout_time', '>=', $startTime)
                    ->where('rtr.payout_time', '<=', $endTime));

            // 即時（type=1）依派彩時間排序，其餘依輸贏金額排序
            $query = $type === 1 ? $query->orderByDesc('rtr.payout_time') : $query->orderByDesc('win_lose');

            $rows = $query->groupBy('rtr.member_id', 'rtr.vendor_id')
                ->select([
                    'm.username',
                    DB::raw('DATE_ADD(rtr.payout_time, INTERVAL 8 HOUR) as payout_time'),
                    DB::raw('MAX(rtr.win_lose) as win_lose'),
                    DB::raw('IFNULL(gn.name, g.name) as game_name'),
                    DB::raw('IFNULL(vci.name, v.name) as vendor_name'),
                ])
                ->limit(30)
                ->get();

            return $rows->map(function ($row) {
                $row->username = Common::maskString((string) $row->username);

                return $row;
            });
        });
    }

    /**
     * 中獎排行榜的時間區間；即時（type=1）不篩選，回傳 [null, null]
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveWinningRankingRange(int $type): array
    {
        if ($type === 1) {
            return [null, null];
        }

        return $this->resolveRankingRange($type);
    }

    /**
     * 平台投注排行榜（`ranking/platform_betting`）
     *
     * tpower-master 與 vw-master 逐行完全相同。日排行（type=1/2）刻意顯示「前一個frontend日」
     * 的投注總額（舊版註記：24+8=32 小時前），週/月排行為當週/當月。
     *
     * 名次不採「同分同名次」，逐列依序編號（舊版 `<=` 判斷在遞減排序下恆為真，等同 `index+1`）。
     *
     * @return Collection<int, mixed>
     */
    public function getPlatformBettingRanking(Agent $agent, int $type): Collection
    {
        $key = CacheKeys::platformBettingRankingList($agent->id, $type);

        return Cache::safeRemember($key, CacheTtl::DEFAULT->value, function () use ($agent, $type) {
            [$startTime, $endTime] = $type <= 2
                ? $this->resolveYesterdayRange()
                : $this->resolveRankingRange($type);

            $subQuery = DB::table('summary_bet_records as sbr')
                ->join('members as m', 'm.id', 'sbr.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->where('sbr.data_time', '>=', $startTime)
                ->where('sbr.data_time', '<=', $endTime)
                ->where($this->hierarchyPathScope('a.hierarchy_path', $agent))
                ->groupBy('sbr.member_id')
                ->select([
                    DB::raw('SUM(sbr.bet) as sum_bet'),
                    'm.username',
                ]);

            $rows = DB::query()
                ->fromSub($subQuery, 's')
                ->where('s.sum_bet', '>=', self::MIN_THRESHOLD)
                ->orderByDesc('s.sum_bet')
                ->select(['s.sum_bet', 's.username'])
                ->limit(30)
                ->get();

            return $rows->values()->map(function ($row, int $index) {
                $row->username = Common::maskString((string) $row->username);
                $row->rank = $index + 1;

                return $row;
            });
        });
    }

    /**
     * 週排行取前一日 frontend 時間的整天區間（前一個 frontend 日的 00:00–23:59）
     *
     * @return array{0: string, 1: string}
     */
    private function resolveYesterdayRange(): array
    {
        $yesterday = now()->toFrontendTime()->subDay();

        return [
            $yesterday->copy()->startOfDay()->toSystemTime()->toDateTimeString(),
            $yesterday->copy()->endOfDay()->toSystemTime()->toDateTimeString(),
        ];
    }

    /**
     * 日／週／月排行的 frontend 時間區間
     *
     * @return array{0: string, 1: string}
     */
    private function resolveRankingRange(int $type): array
    {
        $now = now()->toFrontendTime();

        return match ($type) {
            3 => [
                $now->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toSystemTime()->toDateTimeString(),
                $now->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->toSystemTime()->toDateTimeString(),
            ],
            4 => [
                $now->copy()->startOfMonth()->toSystemTime()->toDateTimeString(),
                $now->copy()->endOfMonth()->toSystemTime()->toDateTimeString(),
            ],
            default => [
                $now->copy()->startOfDay()->toSystemTime()->toDateTimeString(),
                $now->copy()->endOfDay()->toSystemTime()->toDateTimeString(),
            ],
        };
    }

    public function getFakeData(Agent $agent): RankingFakeData
    {
        $fakeKey = CacheKeys::rankingFakeList($agent->id, date('Y-m-d'));
        $vendorService = new VendorService;
        $maintenanceVendorIds = $vendorService->getMaintenanceVendorIds(
            $agent,
            [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::GAME_CLOSED]
        );

        $fakeData = Cache::remember($fakeKey, CacheTtl::ONE_DAY->value, function () use ($agent, $vendorService, $maintenanceVendorIds) {
            $vendors = $vendorService->getVendorIds($agent);
            $vendors = array_diff($vendors, [4, 104, 169], $maintenanceVendorIds);

            $vendorCustomizedItems = VendorCustomizedItem::where('status', 1)
                ->where('agent_group_id', $agent->vendor_customize_group_id)
                ->whereIn('vendor_id', $vendors)
                ->whereHas('vendor', fn ($query) => $query->where('is_free', 0))
                ->with('vendor:id,name')
                ->select('id', 'vendor_id', 'name')
                ->get();

            $fakeRecords = [];
            for ($i = 0; $i < 30; $i++) {
                $randomVendor = $vendorCustomizedItems->random();
                $fakeRecords[] = RankingFakeRecordData::from([
                    'username' => Common::maskString(strtolower(Str::random(rand(6, 8)))),
                    'image' => null,
                    'vendor_id' => $randomVendor->vendor_id,
                    'vendor_name' => $randomVendor->name ?? $randomVendor->vendor->name,
                    'game_total_win_lose' => Common::numMul(rand(110000, 200000), 0.01),
                    'profit_total_win_lose' => Common::numMul(rand(-10000, 10000), 0.01),
                ]);
            }

            $fakeData = RankingFakeData::from([
                'last_update' => time(),
                'data' => $fakeRecords,
            ]);

            return $fakeData;
        });

        // 若最後更新時間小於 1 小時，則不更新緩存
        if (time() - $fakeData->last_update < 3600) {
            return $fakeData;
        }

        // 更新假資料
        $fakeData->data = $fakeData->data->map(function ($item) use ($maintenanceVendorIds) {
            if (isset($item->vendor_id) && in_array($item->vendor_id, $maintenanceVendorIds)) {
                return $item;
            }

            $item->game_total_win_lose = Common::numAdd($item->game_total_win_lose, rand(10000, 100000) * 0.01);
            $item->profit_total_win_lose = Common::numAdd($item->profit_total_win_lose, rand(-10000, 10000) * 0.01);

            return $item;
        });
        $fakeData->last_update = time();
        Cache::put($fakeKey, $fakeData, CacheTtl::ONE_DAY->value);

        return $fakeData;
    }

    public function setRank(Collection &$data): void
    {
        $previousSumBet = null;
        $currentRank = 0;
        $data = $data->map(function ($item, $index) use (&$previousSumBet, &$currentRank) {
            if ($index === 0 || $item->total_win_lose < $previousSumBet) {
                $currentRank = $index + 1;
            }
            $item->rank = $currentRank;
            $previousSumBet = $item->total_win_lose;

            return $item;
        });
    }
}
