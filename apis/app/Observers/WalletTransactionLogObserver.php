<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Models\WalletTransactionLog;
use Illuminate\Support\Facades\Cache;

class WalletTransactionLogObserver
{
    private function clearCache(WalletTransactionLog $walletTransactionLog): void
    {
        $cacheKey = CacheKeys::systemNotify($walletTransactionLog->member_id);
        Cache::store('json')->delete($cacheKey);
    }

    public function created(WalletTransactionLog $walletTransactionLog): void
    {
        $this->clearCache($walletTransactionLog);
    }

    public function updated(WalletTransactionLog $walletTransactionLog): void
    {
        $this->clearCache($walletTransactionLog);
    }

    public function deleted(WalletTransactionLog $walletTransactionLog): void
    {
        $this->clearCache($walletTransactionLog);
    }

    public function forceDeleted(WalletTransactionLog $walletTransactionLog): void
    {
        $this->clearCache($walletTransactionLog);
    }
}
