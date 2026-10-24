<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Helpers\Common;
use App\Models\OrderType;

class OrderTypeObserver
{
    private function clearCache(OrderType $orderType): void
    {
        $cacheKeys = [];

        if ($orderType->parent != 0) {
            $cacheKeys[] = CacheKeys::orderTypeBanks();
        }

        Common::deleteCacheKeys($cacheKeys);
    }

    public function created(OrderType $orderType): void
    {
        $this->clearCache($orderType);
    }

    public function updated(OrderType $orderType): void
    {
        $this->clearCache($orderType);
    }

    public function deleted(OrderType $orderType): void
    {
        $this->clearCache($orderType);
    }

    public function forceDeleted(OrderType $orderType): void
    {
        $this->clearCache($orderType);
    }
}
