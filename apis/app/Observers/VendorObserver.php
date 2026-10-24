<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\Vendor;
use Illuminate\Support\Facades\Cache;

class VendorObserver
{
    public function created(Vendor $vendor): void
    {
        //
    }

    public function updated(Vendor $vendor): void
    {
        $cacheKey = CacheKeys::vendor($vendor->id);
        Cache::forget($cacheKey);
    }

    public function deleted(Vendor $vendor): void
    {
        $cacheKey = CacheKeys::vendor($vendor->id);
        Cache::forget($cacheKey);
    }

    public function forceDeleted(Vendor $vendor): void
    {
        $cacheKey = CacheKeys::vendor($vendor->id);
        Cache::forget($cacheKey);
    }
}
