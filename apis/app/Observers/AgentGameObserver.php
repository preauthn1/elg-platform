<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\AgentGame;
use Illuminate\Support\Facades\Cache;

class AgentGameObserver
{
    /**
     * Handle the AgentGame "created" event.
     */
    public function created(AgentGame $agentGame): void
    {
        $cacheKey = CacheKeys::agentGames($agentGame->agent_id, $agentGame->game->vendor_id);
        Cache::forget($cacheKey);
    }

    /**
     * Handle the AgentGame "updated" event.
     */
    public function updated(AgentGame $agentGame): void
    {
        $dirty = $agentGame->getDirty();

        if (isset($dirty['status'])) {
            $cacheKey = CacheKeys::agentGames($agentGame->agent_id, $agentGame->game->vendor_id);
            Cache::forget($cacheKey);
        }
    }

    /**
     * Handle the AgentGame "deleted" event.
     */
    public function deleted(AgentGame $agentGame): void
    {
        //
    }

    /**
     * Handle the AgentGame "restored" event.
     */
    public function restored(AgentGame $agentGame): void
    {
        //
    }

    /**
     * Handle the AgentGame "force deleted" event.
     */
    public function forceDeleted(AgentGame $agentGame): void
    {
        //
    }
}
