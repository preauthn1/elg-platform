<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\TransactionLog;
use Illuminate\Support\Facades\Cache;

class TransactionLogObserver
{
    private function clearCache(TransactionLog $transactionLog): void
    {
        $cacheKey = CacheKeys::systemNotify($transactionLog->member_id);
        Cache::store('json')->delete($cacheKey);
    }

    public function created(TransactionLog $transactionLog): void
    {
        $this->clearCache($transactionLog);
    }

    public function updated(TransactionLog $transactionLog): void
    {
        $this->clearCache($transactionLog);
    }

    public function deleted(TransactionLog $transactionLog): void
    {
        $this->clearCache($transactionLog);
    }

    public function forceDeleted(TransactionLog $transactionLog): void
    {
        $this->clearCache($transactionLog);
    }
}
