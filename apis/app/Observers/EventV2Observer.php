<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\EventV2;
use Illuminate\Support\Facades\Cache;

class EventV2Observer
{
    public function created(EventV2 $eventV2): void
    {
        Cache::forget(CacheKeys::eventV2Schedule());
    }

    public function updated(EventV2 $eventV2): void
    {
        Cache::forget(CacheKeys::eventV2Schedule());
    }

    public function deleted(EventV2 $eventV2): void
    {
        Cache::forget(CacheKeys::eventV2Schedule());
    }
}
