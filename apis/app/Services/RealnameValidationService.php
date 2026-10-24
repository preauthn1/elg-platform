<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Models\Member;
use App\Models\RealnameValidationRequest;
use Carbon\Carbon;

class RealnameValidationService
{
    public static function isEventRewardRealnameValidated(array $agentSettings, Member $member): bool
    {
        $isEventRewardRealnameValidation = $agentSettings[AgentCustomizedSettingKeyEnum::EVENT_REWARD_REALNAME_VALIDATION] ?? false;

        if (!$isEventRewardRealnameValidation) {
            return true;
        }

        return (bool) $member->realname_validate;
    }

    public static function isWithdrawRealnameValidated(array $agentSettings, Member $member): bool
    {
        $isTransferWithdrawRealnameRequired = $agentSettings[AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED] ?? false;

        if (!$isTransferWithdrawRealnameRequired) {
            return true;
        }

        return (bool) $member->realname_validate;
    }

    public static function isRealnameValidated(Member $member): bool
    {
        return (bool) $member->realname_validate;
    }

    /**
     * 會員最早一次通過實名認證的時間，查無通過紀錄時回傳 null。
     *
     * 用於「註冊送」（action 11）逾期取消判斷：呼叫端自行與
     * members.created_at + event_details.expire_hour 比對。
     */
    public static function getEarliestVerifiedAt(Member $member): ?Carbon
    {
        $verifiedAt = RealnameValidationRequest::query()
            ->where('member_id', $member->id)
            ->where('status', 1)
            ->oldest('updated_at')
            ->value('updated_at');

        return $verifiedAt !== null ? Carbon::parse($verifiedAt) : null;
    }
}
