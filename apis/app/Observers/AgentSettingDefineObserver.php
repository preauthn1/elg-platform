<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\AgentSettingDefine;
use Illuminate\Support\Facades\Cache;

class AgentSettingDefineObserver
{
    public function created(AgentSettingDefine $agentSettingDefine): void
    {
        //
    }

    public function updated(AgentSettingDefine $agentSettingDefine): void
    {
        $dirty = $agentSettingDefine->getDirty();

        if (isset($dirty['value'])) {
            $cacheKey = CacheKeys::agentSettingDefine($agentSettingDefine->setting_id);
            Cache::forget($cacheKey);
        }
    }

    public function deleted(AgentSettingDefine $agentSettingDefine): void
    {
        $cacheKey = CacheKeys::agentSettingDefine($agentSettingDefine->setting_id);
        Cache::forget($cacheKey);
    }

    public function forceDeleted(AgentSettingDefine $agentSettingDefine): void
    {
        $cacheKey = CacheKeys::agentSettingDefine($agentSettingDefine->setting_id);
        Cache::forget($cacheKey);
    }
}
