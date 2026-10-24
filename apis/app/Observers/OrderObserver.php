<?php

declare(strict_types=1);

namespace App\Observers;

use App\CacheKeys\CacheKeys;
use App\Enums\OrderStatusEnum;
use App\Enums\Statistics\MemberHourlySourceEnum;
use App\Jobs\UpdateMemberHourSummary;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;

class OrderObserver
{
    private function clearCache(Order $order): void
    {
        $cacheKey = CacheKeys::systemNotify($order->member_id);
        Cache::store('json')->delete($cacheKey);
    }

    public function created(Order $order): void
    {
        $this->clearCache($order);
    }

    public function updated(Order $order): void
    {
        $this->clearCache($order);
        $this->dispatchHourSummaryOnSuccess($order);
    }

    public function deleted(Order $order): void
    {
        $this->clearCache($order);
    }

    public function forceDeleted(Order $order): void
    {
        $this->clearCache($order);
    }

    /**
     * 代收訂單剛轉為成功（status→DEPOSIT_FINISHED）時，累加會員小時匯總（非同步）。
     * 只在 status 由非成功→成功時觸發一次，避免重複計數。
     */
    private function dispatchHourSummaryOnSuccess(Order $order): void
    {
        $success = OrderStatusEnum::DEPOSIT_FINISHED->value;

        // member_id = 0 是代理自助上分（agent_id 帶代理），是代理的錢，不進會員每小時統計
        if (!$order->wasChanged('status')
            || (int) $order->status !== $success
            || (int) $order->getOriginal('status') === $success
            || (int) $order->member_id <= 0
        ) {
            return;
        }

        // 來源分類（target/tickets#4333）：金流商 AutoDeposit（前台上傳憑證、後台審核）＝自助上分，其餘＝三方充值
        $source = MemberHourlySourceEnum::forDepositOrder($order->orderType?->cash_flow_id !== null ? (int) $order->orderType->cash_flow_id : null);

        UpdateMemberHourSummary::dispatchSafely(
            $order->member_id,
            UpdateMemberHourSummary::TYPE_DEPOSIT,
            (string) $order->point,
            // 以 pay_time 分桶：付款成功當下寫入、之後不變，避免 updated_at 被後續更新而漂移，
            // 並與 member:correct-hourly-statistics 校正命令同基準。fallback updated_at 防極端 null。
            (string) ($order->pay_time ?? $order->updated_at),
            $source->value,
        );
    }
}
