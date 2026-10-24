<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2FieldEnum;
use App\Enums\EventV2\EventV2FieldParamKindEnum;
use App\Helpers\Common;
use App\Models\EventV2Detail;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;

class DailyDepositRangeService extends AbstractEventV2
{
    protected const bool READS_DEPOSIT = true; // 參數說明彈窗：儲值類

    // 獎勵不讀公式，整段讀獎勵配置的區間陣列（由上往下累加、成立即停）
    protected const array HANDLER_FIELD_PARAMS = [
        EventV2FieldEnum::REWARD_FORMULA->value => ['kind' => EventV2FieldParamKindEnum::NONE, 'items' => []],
        EventV2FieldEnum::REWARD_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::ARRAY_TEMPLATE, 'items' => ['range_reward']],
    ];

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

        return $this->checkDeposit($eventDetail, $member);
    }

    public function getReward(EventV2Detail $eventDetail, Member $member): float
    {
        if ($this->reward === null) {
            $this->reward = 0;
            foreach ($eventDetail->reward_config as $config) {
                $this->reward = (float) Common::numAdd($this->reward, $config['reward']);

                if (str_contains($config['condition'], 'deposit')) {
                    $this->executor->setVar('deposit', (float) ($this->deposit?->amount ?? 0));
                }

                if ($this->executor->execute($config['condition']) === true) {
                    break;
                }
            }
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
