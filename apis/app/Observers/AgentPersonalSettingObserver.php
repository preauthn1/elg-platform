<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Models\AgentPersonalSetting;
use Illuminate\Support\Facades\Cache;

class AgentPersonalSettingObserver
{
    /**
     * Handle the AgentCustomizedSetting "created" event.
     */
    public function created(AgentPersonalSetting $agentPersonalSetting): void
    {
        switch ($agentPersonalSetting->setting_id) {
            case AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG:
                if ($agentPersonalSetting->value == 1) {
                    $cacheKey = CacheKeys::maintainAgents();
                    Cache::forget($cacheKey);
                }
                break;
            default:
                break;
        }
    }

    /**
     * Handle the AgentCustomizedSetting "updated" event.
     */
    public function updated(AgentPersonalSetting $agentPersonalSetting): void
    {
        $dirty = $agentPersonalSetting->getDirty();

        // 刪除維護代理緩存
        if ($dirty['setting_id'] == AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG) {
            $cacheKey = CacheKeys::maintainAgents();
            Cache::forget($cacheKey);
        }
    }

    /**
     * Handle the AgentCustomizedSetting "deleted" event.
     */
    public function deleted(AgentPersonalSetting $agentPersonalSetting): void
    {
        // 刪除維護代理緩存
        if ($agentPersonalSetting->setting_id == AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG) {
            $cacheKey = CacheKeys::maintainAgents();
            Cache::forget($cacheKey);
        }
    }

    /**
     * Handle the AgentCustomizedSetting "force deleted" event.
     */
    public function forceDeleted(AgentPersonalSetting $agentPersonalSetting): void
    {
        // 刪除維護代理緩存
        if ($agentPersonalSetting->setting_id == AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG) {
            $cacheKey = CacheKeys::maintainAgents();
            Cache::forget($cacheKey);
        }
    }
}
