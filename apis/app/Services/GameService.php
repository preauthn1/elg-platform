<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GameService
{
    public function __construct(
        protected FlowLimitService $flowLimitService,
    ) {}

    /**
     * getPromotionGames 的 recent_games：近一個月會員自己投注過的遊戲
     *
     * 遷移自 API_Frontend GameController::getPromotionGames（vw-master 獨有）。
     *
     * @return Collection<int, mixed>
     */
    public function getRecentPromotionGames(Member $member, int $languageId): Collection
    {
        $now = now();

        return $this->queryPromotionGames($languageId, function ($query) use ($member, $now) {
            $query->where('l.member_id', $member->id)
                ->where('l.summary_time', '>=', $now->copy()->subMonth())
                ->where('l.summary_time', '<=', $now);
        }, $member);
    }

    /**
     * getPromotionGames 的 recommend_games：當日（frontend 時區）全平台投注過的遊戲
     *
     * @return Collection<int, mixed>
     */
    public function getRecommendPromotionGames(Member $member, int $languageId): Collection
    {
        $now = now();
        $todayStart = $now->copy()->toFrontendTime()->startOfDay()->toSystemTime();
        $todayEnd = $now->copy()->toFrontendTime()->endOfDay()->toSystemTime();

        return $this->queryPromotionGames($languageId, function ($query) use ($todayStart, $todayEnd) {
            $query->where('l.summary_time', '>=', $todayStart)->where('l.summary_time', '<=', $todayEnd);
        }, $member);
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): void  $scope
     * @return Collection<int, mixed>
     */
    private function queryPromotionGames(int $languageId, callable $scope, Member $member): Collection
    {
        $query = DB::table('summary_bet_records as l')
            ->join('games as g', fn ($join) => $join->on('g.id', 'l.game_id')->where('g.status', 1))
            ->join('vendors as v', 'v.id', 'g.vendor_id')
            ->leftJoin('game_names as n', function ($join) use ($languageId) {
                $join->on('n.game_id', 'g.id')->where('n.language_id', $languageId);
            });

        $scope($query);

        $rows = $query->orderByDesc('l.summary_time')
            ->groupBy('l.game_id')
            ->select([
                'l.game_id as id',
                'g.vendor_id',
                'g.type_id',
                'g.rtp',
                DB::raw('(CASE WHEN n.name is NULL THEN g.name ELSE n.name END) as name'),
                'g.lottery_pool',
                DB::raw("(CASE WHEN (g.image is NULL OR g.image = '') THEN v.image ELSE g.image END) as image"),
            ])
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        // disable 沿用既有 FlowLimitService（鎖分模式下限制可玩的遊戲商／遊戲類型），
        // 與 GameController::list() 同一套判斷邏輯，不重寫舊版 event_vendor_locks 專屬查詢
        $games = Game::with('vendor')->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');

        foreach ($rows as $row) {
            $game = $games->get($row->id);
            $row->disable = $game ? $this->flowLimitService->isGameDisabled($game, $member) : 0;
        }

        return $rows;
    }
}
