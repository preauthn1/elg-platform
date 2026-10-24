<?php

declare(strict_types=1);

namespace App\Services\EventV2\Traits;

use App\Enums\EventV2\EventV2AccountBlockEnum;
use App\Enums\EventV2\EventV2IpBlockEnum;
use App\Enums\EventV2\EventV2PhoneBlockEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Helpers\Common;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\RewardClaimRecord;
use App\Services\EventV2\EventV2ValidateResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 活動 V2 的 IP／手機／實名 重複參加封鎖邏輯。
 *
 * 自 {@see \App\Services\EventV2\AbstractEventV2} 抽出的純封鎖判斷，不呼叫任何抽象方法，
 * 純粹依 EventV2Detail 的 ip_block/phone_block/account_block 設定與現有參加紀錄比對。
 */
trait EnforcesParticipationBlocks
{
    private function checkIpBlock(EventV2Detail $eventDetail, Member $member): EventV2ValidateResult
    {
        $code = $this->detectIpBlockCode($eventDetail, $member);
        if ($code === null) {
            return EventV2ValidateResult::success();
        }

        $ip = Common::getIp();
        $logContext = ['member_id' => $member->id, 'member_username' => $member->username, 'ip' => $ip];
        match ($code) {
            EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_REGISTER => Log::warning('member ip exists', [...$logContext, 'ip2long' => ip2long($ip)]),
            EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V1_V2 => Log::warning('event ip exists', $logContext),
            EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V2 => Log::warning('event v2 join ip exists', $logContext),
            default => null,
        };

        return EventV2ValidateResult::fail($code);
    }

    private function checkPhoneBlock(EventV2Detail $eventDetail, Member $member): EventV2ValidateResult
    {
        $code = $this->detectPhoneBlockCode($eventDetail, $member);
        if ($code === null) {
            return EventV2ValidateResult::success();
        }

        $logContext = ['member_id' => $member->id, 'member_username' => $member->username, 'phone' => $member->mobile];
        match ($code) {
            EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_REGISTER => Log::warning('member phone exists', $logContext),
            EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V1_V2 => Log::warning('event phone exists', $logContext),
            EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V2 => Log::warning('event v2 join phone exists', $logContext),
            default => null,
        };

        return EventV2ValidateResult::fail($code);
    }

    private function checkAccountBlock(EventV2Detail $eventDetail, Member $member): EventV2ValidateResult
    {
        $code = $this->detectAccountBlockCode($eventDetail, $member);
        if ($code === null) {
            return EventV2ValidateResult::success();
        }

        $logContext = ['member_id' => $member->id, 'member_username' => $member->username, 'first_name' => $member->first_name];
        match ($code) {
            EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_REGISTER => Log::warning('member account exists', $logContext),
            EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V1_V2 => Log::warning('event account exists', $logContext),
            EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V2 => Log::warning('event v2 join account exists', $logContext),
            default => null,
        };

        return EventV2ValidateResult::fail($code);
    }

    /**
     * 根據 cron schedule 回傳當前週期的 UTC 起訖時間，null 代表無週期限制。
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    private function resolveSchedulePeriod(?string $schedule): ?array
    {
        if ($schedule === null) {
            return null;
        }

        $parts = explode(' ', trim($schedule));
        if (count($parts) !== 5) {
            return null;
        }

        $frontendNow = $this->frontendTime->copy();

        // day-of-week 非萬用字元 → 每周 (e.g. 0 0 * * 1)
        if ($parts[4] !== '*') {
            return [
                'start' => $frontendNow->copy()->startOfWeek()->toSystemTime(),
                'end' => $frontendNow->copy()->endOfWeek()->toSystemTime(),
            ];
        }

        // day-of-month 非萬用字元 → 每月 (e.g. 0 0 1 * *)
        if ($parts[2] !== '*') {
            return [
                'start' => $frontendNow->copy()->startOfMonth()->toSystemTime(),
                'end' => $frontendNow->copy()->endOfMonth()->toSystemTime(),
            ];
        }

        // 預設每日 (e.g. 0 0 * * *)
        return [
            'start' => $frontendNow->copy()->startOfDay()->toSystemTime(),
            'end' => $frontendNow->copy()->endOfDay()->toSystemTime(),
        ];
    }

    private function detectIpBlockCode(EventV2Detail $eventDetail, Member $member): ?EventV2ValidateCodeEnum
    {
        $generalAgentId = $member->agent->general_agent_id;
        $blockArr = explode(',', $eventDetail->ip_block);
        $ip = Common::getIp();
        $period = $this->resolveSchedulePeriod($eventDetail->eventV2?->schedule);

        if (in_array(EventV2IpBlockEnum::ALL_MEMBER->value, $blockArr)) {
            $exists = Member::leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('members.id', '!=', $member->id)
                ->whereIn('members.register_ip', [ip2long($ip), $member->register_ip])
                ->where('members.created_at', '<', $member->created_at)
                ->exists();

            if ($exists) {
                return EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_REGISTER;
            }
        }

        if (in_array(EventV2IpBlockEnum::ALL_EVENT->value, $blockArr)) {
            $v1Exists = RewardClaimRecord::leftJoin('members', 'members.id', '=', 'reward_claim_records.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('reward_claim_records.member_id', '!=', $member->id)
                ->where('reward_claim_records.type', 4)
                ->where('reward_claim_records.value', $ip)
                ->when($period !== null, fn ($q) => $q->whereBetween('reward_claim_records.created_at', [$period['start'], $period['end']]))
                ->exists();

            $v2Exists = EventV2UserJoin::leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('event_v2_user_joins.member_id', '!=', $member->id)
                ->where('event_v2_user_joins.ip', $ip)
                ->when($period !== null, fn ($q) => $q->whereBetween('event_v2_user_joins.created_at', [$period['start'], $period['end']]))
                ->exists();

            if ($v1Exists || $v2Exists) {
                return EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V1_V2;
            }
        }

        if (in_array(EventV2IpBlockEnum::EVENT_V2->value, $blockArr)) {
            $exists = EventV2UserJoin::leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('event_v2_user_joins.member_id', '!=', $member->id)
                ->where('event_v2_user_joins.event_v2_detail_id', $eventDetail->id)
                ->where('event_v2_user_joins.ip', $ip)
                ->when($period !== null, fn ($q) => $q->whereBetween('event_v2_user_joins.created_at', [$period['start'], $period['end']]))
                ->exists();

            if ($exists) {
                return EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V2;
            }
        }

        return null;
    }

    private function detectPhoneBlockCode(EventV2Detail $eventDetail, Member $member): ?EventV2ValidateCodeEnum
    {
        $generalAgentId = $member->agent->general_agent_id;
        $blockArr = explode(',', $eventDetail->phone_block);
        $phoneBindTime = $member->phoneBindings->max('created_at') ?? $member->created_at;
        $period = $this->resolveSchedulePeriod($eventDetail->eventV2?->schedule);

        if (in_array(EventV2PhoneBlockEnum::ALL_MEMBER->value, $blockArr)) {
            $exists = Member::leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->whereHas('phoneBindings', function ($query) use ($member, $phoneBindTime) {
                    $query->where('value', $member->mobile)
                        ->where('created_at', '<', $phoneBindTime);
                })
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('members.id', '!=', $member->id)
                ->where('members.mobile_validate', 1)
                ->exists();

            if ($exists) {
                return EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_REGISTER;
            }
        }

        if (in_array(EventV2PhoneBlockEnum::ALL_EVENT->value, $blockArr)) {
            $v1Exists = RewardClaimRecord::leftJoin('members', 'members.id', '=', 'reward_claim_records.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('reward_claim_records.member_id', '!=', $member->id)
                ->where('reward_claim_records.type', 3)
                ->where('members.mobile_validate', 1)
                ->where('reward_claim_records.value', $member->mobile)
                ->when($period !== null, fn ($q) => $q->whereBetween('reward_claim_records.created_at', [$period['start'], $period['end']]))
                ->exists();

            $v2Exists = EventV2UserJoin::leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('event_v2_user_joins.member_id', '!=', $member->id)
                ->where('event_v2_user_joins.phone', $member->mobile)
                ->when($period !== null, fn ($q) => $q->whereBetween('event_v2_user_joins.created_at', [$period['start'], $period['end']]))
                ->exists();

            if ($v1Exists || $v2Exists) {
                return EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V1_V2;
            }
        }

        if (in_array(EventV2PhoneBlockEnum::EVENT_V2->value, $blockArr)) {
            $exists = EventV2UserJoin::leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('event_v2_user_joins.member_id', '!=', $member->id)
                ->where('event_v2_user_joins.event_v2_detail_id', $eventDetail->id)
                ->where('event_v2_user_joins.phone', $member->mobile)
                ->when($period !== null, fn ($q) => $q->whereBetween('event_v2_user_joins.created_at', [$period['start'], $period['end']]))
                ->exists();

            if ($exists) {
                return EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V2;
            }
        }

        return null;
    }

    private function detectAccountBlockCode(EventV2Detail $eventDetail, Member $member): ?EventV2ValidateCodeEnum
    {
        $generalAgentId = $member->agent->general_agent_id;
        $blockArr = explode(',', $eventDetail->account_block);
        $period = $this->resolveSchedulePeriod($eventDetail->eventV2?->schedule);

        if (in_array(EventV2AccountBlockEnum::ALL_MEMBER->value, $blockArr)) {
            $exists = Member::leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('members.id', '!=', $member->id)
                ->where('members.first_name', $member->first_name)
                // 只認註冊在先的同姓名帳號，避免撞名的兩個帳號互相擋下彼此
                ->where('members.created_at', '<', $member->created_at)
                ->exists();

            if ($exists) {
                return EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_REGISTER;
            }
        }

        if (in_array(EventV2AccountBlockEnum::ALL_EVENT->value, $blockArr)) {
            $v1Exists = RewardClaimRecord::leftJoin('members', 'members.id', '=', 'reward_claim_records.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('reward_claim_records.member_id', '!=', $member->id)
                ->where('reward_claim_records.type', 1)
                ->where('reward_claim_records.value', $member->first_name)
                ->when($period !== null, fn ($q) => $q->whereBetween('reward_claim_records.created_at', [$period['start'], $period['end']]))
                ->exists();

            $v2Exists = EventV2UserJoin::leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('members.id', '!=', $member->id)
                ->where('members.first_name', $member->first_name)
                ->when($period !== null, fn ($q) => $q->whereBetween('event_v2_user_joins.created_at', [$period['start'], $period['end']]))
                ->exists();

            if ($v1Exists || $v2Exists) {
                return EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V1_V2;
            }
        }

        if (in_array(EventV2AccountBlockEnum::EVENT_V2->value, $blockArr)) {
            $exists = EventV2UserJoin::leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
                ->leftJoin('agents', 'agents.id', '=', 'members.parent')
                ->where(function ($query) use ($generalAgentId) {
                    $query->where('agents.hierarchy_path', 'like', '%/' . $generalAgentId . '/%')
                        ->orWhere('members.parent', $generalAgentId);
                })
                ->where('members.id', '!=', $member->id)
                ->where('event_v2_detail_id', $eventDetail->id)
                ->where('members.first_name', $member->first_name)
                ->when($period !== null, fn ($q) => $q->whereBetween('event_v2_user_joins.created_at', [$period['start'], $period['end']]))
                ->exists();

            if ($exists) {
                return EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V2;
            }
        }

        return null;
    }
}
