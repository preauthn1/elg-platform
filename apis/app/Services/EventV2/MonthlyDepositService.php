<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Models\EventV2Detail;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;

class MonthlyDepositService extends AbstractEventV2
{
    protected const bool READS_DEPOSIT = true; // 參數說明彈窗：儲值類，條件公式 deposit、條件配置 main_wallet_deduction

    public function listValidate(EventV2Detail $eventDetail, Collection $participations, Member $member): bool
    {
        $validateEnum = $this->checkMonthly($eventDetail, $participations);

        return $validateEnum->isSuccess();
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        $result = $this->checkMonthly($eventDetail, $participations);

        if ($result->isFailed()) {
            return $result;
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
