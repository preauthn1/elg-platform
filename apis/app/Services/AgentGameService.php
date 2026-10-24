<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Models\Agent;
use App\Models\AgentGame;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class AgentGameService
{
    /**
     * @param  array|null  $vendorIds  if null, get all vendors
     * @return array game ids
     */
    public function getGameIdList(Agent $agent, ?array $vendorIds = null): array
    {
        $agentIds = $agent->hierarchy_array;

        if (is_null($vendorIds)) {
            $vendorIds = (new VendorService)->getVendorIds($agent);
        }

        $vendorIds = collect($vendorIds)
            ->map(fn ($vendorId) => (int) $vendorId)
            ->filter(fn (int $vendorId) => $vendorId > 0)
            ->unique()
            ->values()
            ->toArray();

        if (empty($vendorIds)) {
            return [0];
        }

        $agentIdString = implode(',', $agentIds); // from self to root

        $result = [];
        $missingVendorIds = [];

        foreach ($vendorIds as $vendorId) {
            $key = CacheKeys::agentGames($agent->id, $vendorId);

            $cachedGames = Cache::get($key);
            if (is_array($cachedGames)) {
                $result = array_merge($result, $cachedGames);

                continue;
            }

            $missingVendorIds[] = $vendorId;
        }

        if (!empty($missingVendorIds)) {
            $agentGames = AgentGame::from('agent_games as ag')
                ->join('games as g', fn ($join) => $join
                    ->on('ag.game_id', '=', 'g.id')
                    ->whereIn('g.vendor_id', $missingVendorIds)
                    ->where('g.status', 1)
                )
                ->whereIn('ag.agent_id', $agentIds)
                ->where('ag.status', 1)
                ->select(['ag.id', 'ag.agent_id', 'ag.game_id', 'g.vendor_id'])
                ->orderByRaw("FIELD(ag.agent_id,{$agentIdString})")
                ->get()
                ->groupBy(['agent_id', 'vendor_id']);

            foreach ($missingVendorIds as $vendorId) {
                $games = [];

                foreach ($agentIds as $agentId) {
                    $matchedGames = $agentGames->get($agentId)?->get($vendorId)?->pluck('game_id');
                    if ($matchedGames?->isNotEmpty()) {
                        $games = $matchedGames->toArray();
                        break;
                    }
                }

                Cache::set(CacheKeys::agentGames($agent->id, $vendorId), $games, CacheTtl::ONE_HOUR->value);
                $result = array_merge($result, $games);
            }
        }

        return (count($result) > 0) ? $result : [0];
    }

    /**
     * 取得代理鏈上所有的 agent_games, 並從子代理到根代理排序
     *
     * @return Collection<int, AgentGame>
     */
    public function getValidAgentGames(Agent $agent, int $gameId): Collection
    {
        $agentIds = array_filter($agent->hierarchy_array, fn ($id) => $id > 1); // except admin
        $agentIdString = implode(',', $agentIds); // from self to root

        // 取得代理鏈上所有的 agent_games, 並從子代理到根代理排序
        return Cache::remember(
            key: CacheKeys::validAgentGames($agent->id, $gameId),
            ttl: CacheTtl::ONE_HOUR->value + rand(0, 600),
            callback: fn () => AgentGame::whereIn('agent_id', $agentIds)
                ->where('status', 1)
                ->where('game_id', $gameId)
                ->orderByRaw("FIELD(`agent_id`,{$agentIdString})") // order from self to root
                ->get(),
        );
    }
}
