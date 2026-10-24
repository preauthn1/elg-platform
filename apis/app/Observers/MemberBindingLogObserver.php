<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\MemberBindingLog;
use Illuminate\Support\Facades\Cache;

class MemberBindingLogObserver
{
    private function clearCache(MemberBindingLog $memberBindingLog): void
    {
        $cacheKey = CacheKeys::systemNotify($memberBindingLog->member_id);
        Cache::store('json')->delete($cacheKey);
    }

    public function created(MemberBindingLog $memberBindingLog): void
    {
        $this->clearCache($memberBindingLog);
    }

    public function updated(MemberBindingLog $memberBindingLog): void
    {
        $this->clearCache($memberBindingLog);
    }

    public function deleted(MemberBindingLog $memberBindingLog): void
    {
        $this->clearCache($memberBindingLog);
    }

    public function forceDeleted(MemberBindingLog $memberBindingLog): void
    {
        $this->clearCache($memberBindingLog);
    }
}
