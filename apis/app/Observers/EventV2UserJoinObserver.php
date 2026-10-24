<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Helpers\Common;
use App\Helpers\Traits\Sr11CostTrait;
use App\Models\EventV2UserJoin;
use Illuminate\Support\Facades\Log;

class EventV2UserJoinObserver
{
    const COST_ID = 7;

    use Sr11CostTrait;

    /**
     * Handle the EventV2UserJoin "created" event.
     */
    /**
     * 積分無貨幣價值，不計入 SR11 活動成本（target/tickets#4231 定案：不與金額報表混算）。
     * 看紀錄上參加當下凍結的領取去向，不看活動現值。
     */
    private function isScoreWithdraw(EventV2UserJoin $eventV2UserJoin): bool
    {
        return $eventV2UserJoin->withdraw_type instanceof EventV2WithdrawTypeEnum
            && $eventV2UserJoin->withdraw_type->isScore();
    }

    public function created(EventV2UserJoin $eventV2UserJoin): void
    {
        if ($this->isScoreWithdraw($eventV2UserJoin)) {
            return;
        }

        if ($eventV2UserJoin->eventV2Detail->cost_type === EventV2DetailCostTypeEnum::JOIN->value) {
            $createTime = $eventV2UserJoin->created_at?->toDateTimeString() ?? now()->toDateTimeString();
            Log::info('EventV2 join cost', [
                'eventV2UserJoin' => $eventV2UserJoin->id,
                'point' => $eventV2UserJoin->reward,
                'createTime' => $createTime,
            ]);

            $this->inserCost(
                eventId: self::COST_ID,
                point: Common::numAdd('0', (string) $eventV2UserJoin->reward, 4),
                createTime: $createTime,
            );
        }
    }

    /**
     * Handle the EventV2UserJoin "updated" event.
     */
    public function updated(EventV2UserJoin $eventV2UserJoin): void
    {
        if ($this->isScoreWithdraw($eventV2UserJoin)) {
            return;
        }

        if ($eventV2UserJoin->eventV2Detail->cost_type === EventV2DetailCostTypeEnum::COMPLETE->value && $eventV2UserJoin->status === EventV2UserJoinStatusEnum::COMPLETE->value) {
            $createTime = $eventV2UserJoin->created_at?->toDateTimeString() ?? now()->toDateTimeString();
            Log::info('EventV2 complete cost', [
                'eventV2UserJoin' => $eventV2UserJoin->id,
                'point' => $eventV2UserJoin->withdraw,
                'createTime' => $createTime,
            ]);

            $this->inserCost(
                eventId: self::COST_ID,
                point: Common::numAdd('0', (string) $eventV2UserJoin->withdraw, 4),
                createTime: $createTime,
            );
        }
    }
}
