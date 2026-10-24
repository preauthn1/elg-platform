<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Models\EventV2Detail;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;

class MonthlyDeposit2Service extends AbstractEventV2
{
    protected const bool READS_DEPOSIT = true; // 參數說明彈窗：儲值類，條件公式 deposit、條件配置 main_wallet_deduction

    public function listValidate(EventV2Detail $eventDetail, Collection $participation, Member $member): bool
    {
        $month = $this->frontendTime->format('Y-m');
        if ($participation->where('event_v2_detail_id', $eventDetail->id)->where('month', $month)->isNotEmpty()) {
            return false;
        }

        return true;
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        $result = $this->checkMonthly($eventDetail, $participations);

        if ($result->isFailed()) {
            return $result;
        }

        $month = $this->frontendTime->format('Y-m');
        if ($participations->where('event_v2_id', 3)->where('month', $month)->where('status', EventV2UserJoinStatusEnum::COMPLETE->value)->isEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::EVENT_04_NOT_COMPLETE_EVENT_03); // 您尚未完成"歡迎獎金50%"這個活動，請先完成
        }

        return $this->checkDeposit($eventDetail, $member);
    }

    public function getReward(EventV2Detail $eventDetail, Member $member): float
    {
        if ($this->reward === null) {
            $this->reward = $this->convertRewardFormula($eventDetail, $member);
        }

        return $this->reward;
    }

    public function getBalanceLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->balance_formula, $eventDetail, $member);
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
