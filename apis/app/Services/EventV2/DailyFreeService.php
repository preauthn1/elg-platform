<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Models\EventV2Detail;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;

class DailyFreeService extends AbstractEventV2
{
    public function listValidate(EventV2Detail $eventDetail, Collection $participants, Member $member): bool
    {
        $validateEnum = $this->checkDaily($eventDetail, $participants);

        return $validateEnum->isSuccess();
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        return $this->checkDaily($eventDetail, $participations);
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
