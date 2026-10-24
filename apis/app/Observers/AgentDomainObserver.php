<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\AgentDomain;
use Illuminate\Support\Facades\Cache;

class AgentDomainObserver
{
    /**
     * Handle the AgentDomain "created" event.
     */
    public function created(AgentDomain $agentDomain): void {}

    /**
     * Handle the AgentDomain "updated" event.
     */
    public function updated(AgentDomain $agentDomain): void
    {
        $dirty = $agentDomain->getDirty();

        if (isset($dirty['status'])) {
            $cacheKey = CacheKeys::agentDomain($agentDomain->domain);
            Cache::forget($cacheKey);
        }

    }

    /**
     * Handle the AgentDomain "deleted" event.
     */
    public function deleted(AgentDomain $agentDomain): void
    {
        $cacheKey = CacheKeys::agentDomain($agentDomain->domain);
        Cache::forget($cacheKey);
    }

    /**
     * Handle the AgentDomain "force deleted" event.
     */
    public function forceDeleted(AgentDomain $agentDomain): void
    {
        $cacheKey = CacheKeys::agentDomain($agentDomain->domain);
        Cache::forget($cacheKey);
    }
}
