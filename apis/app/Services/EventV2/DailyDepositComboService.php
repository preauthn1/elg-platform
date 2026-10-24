<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2FieldEnum;
use App\Enums\EventV2\EventV2FieldParamKindEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\OrderStatusEnum;
use App\Models\EventV2Detail;
use App\Models\Member;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

class DailyDepositComboService extends AbstractEventV2
{
    // 參數說明彈窗：條件／獎勵／餘額限制三格配置都是整段陣列（條件配置是陣列，連 max_join 都讀不到）；
    // 不讀條件公式與獎勵公式；餘額門檻只看餘額限制配置，getBalanceLimit() 不讀餘額限制公式；
    // 沒有 $this->deposit，限制公式的 deposit 恆為 0 故不列
    protected const array HANDLER_FIELD_PARAMS = [
        EventV2FieldEnum::CONDITION_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::ARRAY_TEMPLATE, 'items' => ['combo_condition']],
        EventV2FieldEnum::REWARD_FORMULA->value => ['kind' => EventV2FieldParamKindEnum::NONE, 'items' => []],
        EventV2FieldEnum::REWARD_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::ARRAY_TEMPLATE, 'items' => ['combo_reward']],
        EventV2FieldEnum::BALANCE_FORMULA->value => ['kind' => EventV2FieldParamKindEnum::NONE, 'items' => []],
        EventV2FieldEnum::BALANCE_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::ARRAY_TEMPLATE, 'items' => ['combo_balance']],
    ];

    private ?Collection $order = null;

    protected function getOrder(int $memberId): ?Collection
    {
        if ($this->order === null) {
            $timeOffset = $this->frontendTime->offsetHours;
            $this->order = Order::select('amount')
                ->where('member_id', $memberId)
                ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
                ->whereRaw('DATE_FORMAT(DATE_ADD(pay_time, INTERVAL ' . $timeOffset . ' HOUR), "%Y-%m-%d") = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL ' . $timeOffset . ' HOUR), "%Y-%m-%d")')
                ->get();
        }

        return $this->order;
    }

    public function listValidate(EventV2Detail $eventDetail, Collection $participation, Member $member): bool
    {
        $validateEnum = $this->checkDaily($eventDetail, $participation);

        return $validateEnum->isSuccess();
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        $result = $this->checkDaily($eventDetail, $participations);

        if ($result->isFailed()) {
            return $result;
        }

        $vildate = false;
        $order = $this->getOrder($member->id);
        foreach ($eventDetail->condition_config as $config) {
            if ($order->where('amount', $config['deposit'])->count() >= $config['count']) {
                $vildate = true;
                break;
            }
        }
        if ($vildate === false) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH); // 儲值尚未達到標準
        }

        return EventV2ValidateResult::success();
    }

    public function getReward(EventV2Detail $eventDetail, Member $member): float
    {
        if ($this->reward === null) {
            $order = $this->getOrder($member->id);
            foreach ($eventDetail->reward_config as $config) {
                if ($order->where('amount', $config['condition']['deposit'])->count() >= $config['condition']['count']) {
                    $this->reward += $config['reward'];
                }
            }
        }

        return $this->reward;
    }

    public function getBalanceLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        $order = $this->getOrder($member->id);
        $balanceLimit = 0;
        foreach ($eventDetail->balance_config as $config) {
            if ($order->where('amount', $config['condition']['deposit'])->count() >= $config['condition']['count']) {
                $balanceLimit += $config['balance'];
            }
        }

        return $balanceLimit;
    }

    public function getFlowLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->flow_formula, $eventDetail, $member);
    }

    public function getWithdrawLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->withdraw_formula, $eventDetail, $member);
    }
}
