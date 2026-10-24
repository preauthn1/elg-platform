<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Enums\Statistics\MemberHourlySourceEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Jobs\UpdateMemberHourSummary;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Facades\Cache;

class WithdrawalOrderObserver
{
    private function clearCache(WithdrawalOrder $withdrawalOrder): void
    {
        $cacheKey = CacheKeys::systemNotify($withdrawalOrder->member_id);
        Cache::store('json')->delete($cacheKey);
    }

    public function created(WithdrawalOrder $withdrawalOrder): void
    {
        $this->clearCache($withdrawalOrder);
    }

    public function updated(WithdrawalOrder $withdrawalOrder): void
    {
        $this->clearCache($withdrawalOrder);
        $this->dispatchHourSummaryOnSuccess($withdrawalOrder);
    }

    public function deleted(WithdrawalOrder $withdrawalOrder): void
    {
        $this->clearCache($withdrawalOrder);
    }

    public function forceDeleted(WithdrawalOrder $withdrawalOrder): void
    {
        $this->clearCache($withdrawalOrder);
    }

    /**
     * 提款訂單剛轉為成功時，累加會員小時匯總（非同步）。
     * 成功＝三方代付（THIRD_PARTY_SUCCESS）或自助洗分（TRANSFERRED），兩者都是實際出款。
     * 只在 status 由非成功→成功時觸發一次，避免重複計數。
     *
     * 自助洗分多由 API_Agent 寫入、不經本 Observer，缺的由每小時校正排程兜底。
     *
     * 分桶時間用 payment_time（實際打款時間），與 MemberHourlyStatisticsRecomputeService 同基準；三方回調與後台「已轉帳」
     * 都在改狀態的同一次 update 寫入 payment_time，極端 null 時退回 updated_at。
     * 來源分類（target/tickets#4333）：status 24 三方代付＝三方提現、status 4 後台人工轉帳＝自助洗分。
     */
    private function dispatchHourSummaryOnSuccess(WithdrawalOrder $withdrawalOrder): void
    {
        $successStatuses = [
            WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value,
            WithdrawalOrderStatusEnum::TRANSFERRED->value,
        ];

        // member_id = 0 是代理自助洗分（agent_id 帶代理），是代理的錢，不進會員每小時統計
        if (!$withdrawalOrder->wasChanged('status')
            || !in_array((int) $withdrawalOrder->status, $successStatuses, true)
            || in_array((int) $withdrawalOrder->getOriginal('status'), $successStatuses, true)
            || (int) $withdrawalOrder->member_id <= 0
        ) {
            return;
        }

        UpdateMemberHourSummary::dispatchSafely(
            $withdrawalOrder->member_id,
            UpdateMemberHourSummary::TYPE_WITHDRAWAL,
            (string) $withdrawalOrder->point,
            (string) ($withdrawalOrder->payment_time ?? $withdrawalOrder->updated_at),
            MemberHourlySourceEnum::forWithdrawalOrderStatus((int) $withdrawalOrder->status)?->value,
        );
    }
}
