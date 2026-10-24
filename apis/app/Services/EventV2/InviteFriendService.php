<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2FieldEnum;
use App\Enums\EventV2\EventV2FieldParamKindEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\OrderStatusEnum;
use App\Models\EventV2Detail;
use App\Models\EventV2Invite;
use App\Models\Member;
use App\Models\Order;
use App\Services\BloomFilter\MemberFirstNameFilter;
use App\Services\BloomFilter\MemberMobileFilter;
use App\Services\BloomFilter\MemberRegisterIpFilter;
use App\Services\MemberCodeService;
use Illuminate\Database\Eloquent\Collection;
use NXP\MathExecutor;

class InviteFriendService extends AbstractEventV2
{
    // 參數說明彈窗：條件配置讀 invite／deposit（被邀請人最低儲值）；獎勵公式自己執行、不經 convertRewardFormula()，
    // 所以只有 invite 沒有 rand()，也不讀獎勵配置的 limit
    protected const array HANDLER_FIELD_PARAMS = [
        EventV2FieldEnum::CONDITION_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::KEYS, 'items' => ['max_join', 'invite', 'invite_deposit']],
        EventV2FieldEnum::REWARD_FORMULA->value => ['kind' => EventV2FieldParamKindEnum::VARIABLES, 'items' => ['invite']],
        EventV2FieldEnum::REWARD_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::NONE, 'items' => []],
    ];

    protected bool $isInvite = true;

    // 原始邀請人
    public ?Collection $originalInvitedMembers = null;

    // 過濾後的邀請人
    public ?Collection $invitedMembers = null;

    public function __construct(
        protected MathExecutor $executor,
        protected EventV2Service $eventV2Service,
        protected MemberCodeService $memberCodeService,
        protected MemberRegisterIpFilter $memberRegisterIpFilter,
        protected MemberMobileFilter $memberMobileFilter,
        protected MemberFirstNameFilter $memberFirstNameFilter
    ) {
        parent::__construct(
            $executor,
            $eventV2Service
        );
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        $memberCode = $this->memberCodeService->get($member);

        $invitedRecords = EventV2Invite::select('event_v2_invites.member_id')
            ->leftJoin('event_v2_user_joins', 'event_v2_invites.event_v2_user_join_id', '=', 'event_v2_user_joins.id')
            ->where('event_v2_user_joins.member_id', $member->id)
            ->get()
            ->pluck('member_id')
            ->toArray();

        $members = Member::select('id', 'register_ip', 'mobile', 'first_name', 'created_at')
            ->whereNotIn('id', $invitedRecords)
            ->where('invitation_code', $memberCode->agent_code)
            ->where('created_at', '>=', $eventDetail->start_time)
            ->orderBy('created_at', 'asc') // 先排序，確保最早的在前
            ->get();

        $this->originalInvitedMembers = $members;
        $originalInvitedMemberIds = $members->pluck('id')->toArray();

        // 被邀請人之間互相過濾IP / 手機 / 姓名
        $filtered = $members
            ->unique('register_ip')   // 先過濾掉重複 IP（保留最早）
            ->unique('mobile')        // 再過濾掉重複手機
            ->unique('first_name');   // 最後過濾掉重複真實姓名

        $checkIp = $filtered->pluck('register_ip')->toArray();
        $checkMobile = $filtered->whereNotNull('mobile')->pluck('mobile')->toArray();
        $checkFirstName = $filtered->pluck('first_name')->toArray();

        // 過濾掉跟全站有重複的被邀請人
        $duplicateIp = $this->memberRegisterIpFilter->getDuplicateData($checkIp, $originalInvitedMemberIds, true);
        $duplicateMobile = $this->memberMobileFilter->getDuplicateData($checkMobile, $originalInvitedMemberIds);
        $duplicateFirstName = $this->memberFirstNameFilter->getDuplicateData($checkFirstName, $originalInvitedMemberIds);

        $this->invitedMembers = $filtered
            ->whereNotIn('register_ip', $duplicateIp)
            ->whereNotIn('mobile', $duplicateMobile)
            ->whereNotIn('first_name', $duplicateFirstName)
            ->values();

        if ($this->invitedMembers->count() < $eventDetail->condition_config['invite']) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::EVENT_13_INVITE_NOT_ENOUGH)
                ->setTranslateReplace(['invite' => $this->invitedMembers->count()]); // 邀請人數不足
        }

        if (isset($eventDetail->condition_config['deposit']) === true) {
            $deposit = Order::select('amount')
                ->where('member_id', $member->id)
                ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
                ->where('amount', '>=', $eventDetail->condition_config['deposit'])
                ->get();
            if ($deposit->isEmpty()) {
                return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH); // 儲值尚未達到標準
            }
        }

        return EventV2ValidateResult::success();
    }

    public function getReward(EventV2Detail $eventDetail, Member $member): float
    {
        if ($this->reward === null) {
            if (str_contains($eventDetail->reward_formula, 'invite')) {
                $this->executor->setVar('invite', $this->invitedMembers->count());
            }

            $this->reward = (float) $this->executor->execute($eventDetail->reward_formula);
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

    public function insertInviteMember(int $userJoinId): void
    {
        foreach ($this->originalInvitedMembers as $invitedMember) {
            EventV2Invite::create([
                'event_v2_user_join_id' => $userJoinId,
                'member_id' => $invitedMember->id,
            ]);
        }
    }
}
