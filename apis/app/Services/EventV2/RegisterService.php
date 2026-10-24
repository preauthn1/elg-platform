<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2FieldEnum;
use App\Enums\EventV2\EventV2FieldParamKindEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Models\EventV2Detail;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;

class RegisterService extends AbstractEventV2
{
    // 參數說明彈窗：條件配置多 realname；條件公式雖會在含 deposit 時走 checkDeposit()，但註冊沒有儲值訂單，不列
    protected const array HANDLER_FIELD_PARAMS = [
        EventV2FieldEnum::CONDITION_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::KEYS, 'items' => ['max_join', 'realname']],
    ];

    public function listValidate(EventV2Detail $eventDetail, Collection $participation, Member $member): bool
    {
        if ($member->created_at < $eventDetail->start_time || $member->created_at > $eventDetail->end_time) {
            return false;
        }

        if ($participation->where('event_v2_detail_id', $eventDetail->id)->isNotEmpty()) {
            return false;
        }

        return true;
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        if ($member->created_at < $eventDetail->start_time || $member->created_at > $eventDetail->end_time) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::EVENT_01_NOT_IN_TIME); // 註冊時間不再活動時間內
        }

        if ($participations->where('event_v2_detail_id', $eventDetail->id)->isNotEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::EVENT_01_ONLY_ONE_TIMES); // 註冊活動只能參加一次
        }

        if (isset($eventDetail->condition_config['realname']) && $eventDetail->condition_config['realname'] == 1) {
            if ($member->realname_validate == 0) {
                return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::EVENT_01_REALNAME); // 請先完成實名認證
            }
        }

        if (str_contains($eventDetail->condition_formula ?? '', 'deposit')) {
            return $this->checkDeposit($eventDetail, $member);
        }

        return EventV2ValidateResult::success();
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
