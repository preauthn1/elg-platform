<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Models\Agent;
use App\Models\MarqueeWinPoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MarqueeService
{
    /**
     * 撈出遊戲商與達標金額的規則, 如果admin與代理同時設置且啟用狀態, 優先套用代理的配置
     *
     * TODO: 使用Cache::safeRemember?
     */
    public function getTriggerList(Agent $agent): Collection
    {
        $key = CacheKeys::marqueeService(__FUNCTION__, $agent->id);

        return Cache::remember($key, CacheTtl::ONE_HOUR->value, function () use ($agent) {
            $agentIdString = implode(',', $agent->hierarchy_array);

            $list = MarqueeWinPoint::query()
                ->select([
                    'id',
                    'agent_id',
                    'vendor_id',
                    'trigger_amount',
                ])
                ->where('status', 1)
                ->whereIn('agent_id', $agent->hierarchy_array)
                ->whereNotIn('exclude_agent_id', $agent->hierarchy_array)
                ->orderByDesc('id')
                ->orderByRaw("FIELD(`agent_id`,{$agentIdString})")
                ->get();

            $triggerList = $list->groupBy('vendor_id')->map(fn ($item) => $item->first()); // 已經照繼承順序排序好，所以直接取第一個

            return $triggerList;
        });
    }
}
