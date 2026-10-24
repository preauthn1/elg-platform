<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\RollingChipLog;
use Illuminate\Support\Facades\Cache;

class RollingChipLogObserver
{
    private function clearCache(RollingChipLog $rollingChipLog): void
    {
        $cacheKey = CacheKeys::systemNotify($rollingChipLog->member_id);
        Cache::store('json')->delete($cacheKey);
    }

    public function created(RollingChipLog $rollingChipLog): void
    {
        $this->clearCache($rollingChipLog);
    }

    public function updated(RollingChipLog $rollingChipLog): void
    {
        $this->clearCache($rollingChipLog);
    }

    public function deleted(RollingChipLog $rollingChipLog): void
    {
        $this->clearCache($rollingChipLog);
    }

    public function forceDeleted(RollingChipLog $rollingChipLog): void
    {
        $this->clearCache($rollingChipLog);
    }
}
