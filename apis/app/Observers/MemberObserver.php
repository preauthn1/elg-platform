<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Member;
use App\Services\WalletService;

class MemberObserver
{
    public function __construct(private readonly WalletService $walletService) {}

    /**
     * Handle the Member "created" event.
     */
    public function created(Member $member): void {}

    /**
     * Handle the Member "updated" event.
     */
    public function updated(Member $member): void
    {
        // 多幣別錢包雙寫：餘額變動時派影子同步 Job（開關預設關閉＝no-op，
        // 見 config/custom.php 的 multi_currency_wallet 節與 WalletService::dispatchShadowSync）
        if ($member->wasChanged('balance')) {
            $this->walletService->dispatchShadowSync($member->id);
        }
    }

    /**
     * Handle the Member "deleted" event.
     */
    public function deleted(Member $member): void {}

    /**
     * Handle the Member "force deleted" event.
     */
    public function forceDeleted(Member $member): void {}
}
