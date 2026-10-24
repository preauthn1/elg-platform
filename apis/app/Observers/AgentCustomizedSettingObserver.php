<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\AgentCustomizedSetting;
use Illuminate\Support\Facades\Cache;

class AgentCustomizedSettingObserver
{
    public function created(AgentCustomizedSetting $agentCustomizedSetting): void
    {
        //
    }

    public function updated(AgentCustomizedSetting $agentCustomizedSetting): void
    {
        $dirty = $agentCustomizedSetting->getDirty();

        if (isset($dirty['value'])) {
            $cacheKey = CacheKeys::agentCustomizedSetting($agentCustomizedSetting->agent_group_id, $agentCustomizedSetting->setting_key);
            Cache::forget($cacheKey);
        }
    }

    public function deleted(AgentCustomizedSetting $agentCustomizedSetting): void
    {
        $cacheKey = CacheKeys::agentCustomizedSetting($agentCustomizedSetting->agent_group_id, $agentCustomizedSetting->setting_key);
        Cache::forget($cacheKey);
    }

    public function forceDeleted(AgentCustomizedSetting $agentCustomizedSetting): void
    {
        $cacheKey = CacheKeys::agentCustomizedSettingGroup($agentCustomizedSetting->agent_group_id, $agentCustomizedSetting->setting_key);
        Cache::forget($cacheKey);
    }
}
