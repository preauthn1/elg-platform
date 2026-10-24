<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Enums\StatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\EventDetail;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2Invite;
use App\Models\Member;
use App\Models\MemberCode;
use App\Services\BloomFilter\MemberFirstNameFilter;
use App\Services\BloomFilter\MemberMobileFilter;
use App\Services\BloomFilter\MemberRegisterIpFilter;
use App\Services\LivechatBot\Concerns\ResolvesV1InviteProgress;
use App\Services\MemberCodeService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use NXP\MathExecutor;

/**
 * Livechat Bot 推薦進度查詢 service。
 *
 * 同時兼容兩種邀請好友活動，並在兩者皆進行中時依 end_time 仲裁（較早者勝、同則 V2 優先）：
 * - V2：EventV2 子類別為 InviteFriendService 的活動（透過 EventV2Invite 計算下線）。
 * - V1：Event action 15（NEW_INVITE_PLAYER_OFFERS），透過 event_invite_records 計算下線。
 *
 * V1 來源另輸出 level-1「邀請並充值」維度（deposit_referral_apply，V1-only）。
 * 同類多個活動進行中時各自取 end_time ASC 第一筆（最緊迫）。
 */
class ReferralProgressService
{
    use ResolvesV1InviteProgress;

    private const string INVITE_HANDLER_CLASS = 'InviteFriendService';

    private const string SOURCE_V1 = 'v1';

    private const string SOURCE_V2 = 'v2';

    private const string DEFAULT_BONUS = '0.00';

    private const int BONUS_SCALE = 2;

    public function __construct(
        protected MemberCodeService $memberCodeService,
        protected MemberRegisterIpFilter $memberRegisterIpFilter,
        protected MemberMobileFilter $memberMobileFilter,
        protected MemberFirstNameFilter $memberFirstNameFilter,
        protected MathExecutor $executor,
        protected LivechatBotContext $context,
        protected MemberAgentGuard $guard,
    ) {}

    /**
     * 取得推薦進度。
     *
     * @return array<string, mixed>
     */
    public function getProgress(string $username): array
    {
        $member = $this->context->findMemberByUsername($username);

        if ($member === null) {
            throw new ApiException('Member not found', 404);
        }

        $memberCode = $this->memberCodeService->get($member);
        $referralCode = $memberCode->agent_code;

        $v2Detail = $this->findActiveInviteEventDetail($member);
        $v1Detail = $this->findActiveV1InviteDetail($member);

        $source = $this->arbitrateSource($v2Detail, $v1Detail);

        if ($source === null) {
            return [
                'referral_code' => $referralCode,
                'register_referral_apply' => $this->buildEmptyProgress($referralCode),
            ];
        }

        if ($source === self::SOURCE_V2) {
            return $this->buildV2Progress($member, $memberCode, $referralCode, $v2Detail);
        }

        return $this->buildV1Progress($member, $referralCode, $v1Detail);
    }

    /**
     * 仲裁採用哪個來源：兩者皆有取 end_time 較早者，同 end_time 時 V2 優先；
     * 只有一個則用該個；皆無回 null（呼叫端回 buildEmptyProgress）。
     *
     * @return 'v1'|'v2'|null
     */
    private function arbitrateSource(?EventV2Detail $v2Detail, ?EventDetail $v1Detail): ?string
    {
        if ($v2Detail === null && $v1Detail === null) {
            return null;
        }

        if ($v1Detail === null) {
            return self::SOURCE_V2;
        }

        if ($v2Detail === null) {
            return self::SOURCE_V1;
        }

        $v2End = Carbon::parse($v2Detail->end_time);
        $v1End = Carbon::parse($v1Detail->end_time);

        // 同 end_time → V2 優先（僅 V1 嚴格較早時才採 V1）。
        return $v1End->lt($v2End) ? self::SOURCE_V1 : self::SOURCE_V2;
    }

    /**
     * V2 來源 register_referral_apply（既有計算，外加 source 欄位）。
     *
     * @return array<string, mixed>
     */
    private function buildV2Progress(
        Member $member,
        MemberCode $memberCode,
        string $referralCode,
        EventV2Detail $detail,
    ): array {
        $targetCount = (int) ($detail->condition_config['invite'] ?? 0);
        $invitedMembers = $this->filterInvitedMembers(member: $member, eventDetail: $detail, memberCode: $memberCode->agent_code);
        $currentCount = $invitedMembers->count();
        $uplineBonus = $this->calculateUplineBonus(eventDetail: $detail, invitedCount: $currentCount);
        $downlines = $invitedMembers->pluck('username')->values()->all();

        return [
            'referral_code' => $referralCode,
            'register_referral_apply' => [
                'current_count' => $currentCount,
                'target_count' => $targetCount,
                'reward_on_completion' => $uplineBonus,
                'downlines' => $downlines,
                'source' => self::SOURCE_V2,
                'message' => $this->buildMessage(
                    referralCode: $referralCode,
                    bonus: $uplineBonus,
                    currentCount: $currentCount,
                    targetCount: $targetCount,
                    downlines: $downlines,
                ),
            ],
        ];
    }

    /**
     * V1 來源（action 15）：register_referral_apply（level-0 純邀請），
     * 並在活動有 level-1 prize 時附帶 deposit_referral_apply（V1-only）。
     *
     * @return array<string, mixed>
     */
    private function buildV1Progress(Member $member, string $referralCode, EventDetail $detail): array
    {
        $invitedIds = $this->filteredInvitedMemberIds($detail, $member);
        $currentCount = count($invitedIds);
        $targetCount = $this->resolveInviteRequired($detail, $currentCount);
        $uplineBonus = $this->inviteRewardForTarget($detail, 0, $targetCount);
        $downlines = $this->usernamesByIds($invitedIds);

        $result = [
            'referral_code' => $referralCode,
            'register_referral_apply' => [
                'current_count' => $currentCount,
                'target_count' => $targetCount,
                'reward_on_completion' => $uplineBonus,
                'downlines' => $downlines,
                'source' => self::SOURCE_V1,
                'message' => $this->buildMessage(
                    referralCode: $referralCode,
                    bonus: $uplineBonus,
                    currentCount: $currentCount,
                    targetCount: $targetCount,
                    downlines: $downlines,
                ),
            ],
        ];

        $depositApply = $this->buildV1DepositProgress($member, $referralCode, $detail);
        if ($depositApply !== null) {
            $result['deposit_referral_apply'] = $depositApply;
        }

        return $result;
    }

    /**
     * V1 level-1「邀請並充值」維度（deposit_referral_apply）。
     *
     * 僅當活動有 level-1 prize 級距時輸出，否則回 null（呼叫端不放入該 key，維持向後相容）。
     *
     * @return array<string, mixed>|null
     */
    private function buildV1DepositProgress(Member $member, string $referralCode, EventDetail $detail): ?array
    {
        if ($this->inviteTiers($detail, 1)->isEmpty()) {
            return null;
        }

        $qualifiedIds = $this->qualifiedDepositInviteeIds($detail, $member);
        $currentCount = count($qualifiedIds);
        $targetCount = $this->resolveInviteRequired($detail, $currentCount, 1);
        $uplineBonus = $this->inviteRewardForTarget($detail, 1, $targetCount);
        $downlines = $this->usernamesByIds($qualifiedIds);
        $depositThreshold = (int) $detail->point;

        return [
            'current_count' => $currentCount,
            'target_count' => $targetCount,
            'reward_on_completion' => $uplineBonus,
            'downlines' => $downlines,
            'deposit_threshold' => $depositThreshold,
            'source' => self::SOURCE_V1,
            'message' => $this->buildDepositMessage(
                referralCode: $referralCode,
                bonus: $uplineBonus,
                currentCount: $currentCount,
                targetCount: $targetCount,
                downlines: $downlines,
                depositThreshold: $depositThreshold,
            ),
        ];
    }

    /**
     * 依 member_id 清單取 username（依 created_at 升冪），保留與下線母體一致的排序。
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, string>
     */
    private function usernamesByIds(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        return Member::query()
            ->whereIn('id', $memberIds)
            ->orderBy('created_at', 'asc')
            // 次級鍵 id：created_at 同秒 tie 時 MySQL 順序無保證，補 id 使排序穩定（避免 flaky）。
            ->orderBy('id', 'asc')
            ->pluck('username')
            ->values()
            ->all();
    }

    /**
     * 查詢進行中、handler_class 為 InviteFriendService 的活動，並取最緊迫一筆（end_time ASC）。
     */
    private function findActiveInviteEventDetail(Member $member): ?EventV2Detail
    {
        $time = Carbon::now()->toDateTimeString();
        $hierarchy = $member->agent?->hierarchy_array ?? [];

        $eventV2Ids = EventV2::query()
            ->where('handler_class', self::INVITE_HANDLER_CLASS)
            ->pluck('id')
            ->all();

        if ($eventV2Ids === []) {
            return null;
        }

        $query = EventV2Detail::query()
            ->whereIn('event_v2_id', $eventV2Ids)
            ->where('start_time', '<=', $time)
            ->where('end_time', '>=', $time)
            ->where('status', StatusEnum::ENABLE->value);

        if ($hierarchy !== []) {
            $query->whereIn('agent_id', $hierarchy);
        }

        return $query->orderBy('end_time', 'asc')->first();
    }

    /**
     * 篩選有效下線（IP / mobile / realname 三層去重，不要求首存）。
     *
     * 與 {@see \App\Services\EventV2\InviteFriendService::getValidate()} 邏輯一致，
     * 但不拋例外，便於未達標時仍能回傳目前進度。
     *
     * @return Collection<int, Member>
     */
    private function filterInvitedMembers(Member $member, EventV2Detail $eventDetail, string $memberCode): Collection
    {
        $invitedRecords = EventV2Invite::query()
            ->select('event_v2_invites.member_id')
            ->leftJoin('event_v2_user_joins', 'event_v2_invites.event_v2_user_join_id', '=', 'event_v2_user_joins.id')
            ->where('event_v2_user_joins.member_id', $member->id)
            ->pluck('member_id')
            ->toArray();

        $members = Member::query()
            ->select('id', 'username', 'register_ip', 'mobile', 'first_name', 'created_at')
            ->whereNotIn('id', $invitedRecords)
            ->where('invitation_code', $memberCode)
            ->where('created_at', '>=', $eventDetail->start_time)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($members->isEmpty()) {
            return new Collection;
        }

        $originalIds = $members->pluck('id')->toArray();

        // 被邀請人之間互相過濾 IP / 手機 / 姓名
        $filtered = $members
            ->unique('register_ip')
            ->unique('mobile')
            ->unique('first_name');

        $checkIp = $filtered->pluck('register_ip')->toArray();
        $checkMobile = $filtered->whereNotNull('mobile')->pluck('mobile')->toArray();
        $checkFirstName = $filtered->pluck('first_name')->toArray();

        // 過濾掉跟全站有重複的被邀請人
        $duplicateIp = $this->memberRegisterIpFilter->getDuplicateData($checkIp, $originalIds, true);
        $duplicateMobile = $this->memberMobileFilter->getDuplicateData($checkMobile, $originalIds);
        $duplicateFirstName = $this->memberFirstNameFilter->getDuplicateData($checkFirstName, $originalIds);

        return $filtered
            ->whereNotIn('register_ip', $duplicateIp)
            ->whereNotIn('mobile', $duplicateMobile)
            ->whereNotIn('first_name', $duplicateFirstName)
            ->values();
    }

    /**
     * 透過 reward_formula 計算上線獎勵。
     *
     * 若公式包含 `invite` 變數，以目前實際邀請數帶入。
     * 計算失敗或 formula 為空時回傳 "0.00"。
     */
    private function calculateUplineBonus(EventV2Detail $eventDetail, int $invitedCount): string
    {
        $formula = $eventDetail->reward_formula;
        if ($formula === null || $formula === '') {
            return self::DEFAULT_BONUS;
        }

        try {
            if (str_contains($formula, 'invite')) {
                $this->executor->setVar('invite', $invitedCount);
            }

            $reward = $this->executor->execute($formula);
        } catch (\Throwable) {
            return self::DEFAULT_BONUS;
        }

        return Common::numAdd('0', (string) $reward, self::BONUS_SCALE);
    }

    /**
     * 無進行中活動時的預設 register_referral_apply 結構。
     *
     * @return array<string, mixed>
     */
    private function buildEmptyProgress(string $referralCode): array
    {
        return [
            'current_count' => 0,
            'target_count' => 0,
            'reward_on_completion' => self::DEFAULT_BONUS,
            'downlines' => [],
            // 無進行中活動 → 無來源；帶 null 使 source key 在空/有活動兩種結構皆存在，schema 一致。
            'source' => null,
            'message' => [
                'Referral Code: ' . $referralCode,
                'No active referral promotion',
            ],
        ];
    }

    /**
     * 組裝對使用者顯示的 message 陣列。
     *
     * @param  array<int, string>  $downlines
     * @return array<int, string>
     */
    private function buildMessage(
        string $referralCode,
        string $bonus,
        int $currentCount,
        int $targetCount,
        array $downlines,
    ): array {
        $downlinesText = $downlines === [] ? '-' : implode(', ', $downlines);

        $messages = [
            'Referral Code: ' . $referralCode,
            'Register Bonus: ' . $bonus,
            'Progress: ' . $currentCount . '/' . $targetCount,
            'Registered Downlines: ' . $downlinesText,
        ];

        if (Common::numCompare((string) $currentCount, (string) $targetCount) >= 0) {
            $messages[] = 'Bonus of ' . $bonus . ' reached!';
        } else {
            $remaining = $targetCount - $currentCount;
            $messages[] = 'Complete ' . $remaining . ' more registration referral to get bonus of ' . $bonus . '!';
        }

        return $messages;
    }

    /**
     * 組裝 V1 level-1「邀請並充值」維度的 message 陣列（deposit 語意，含最低充值門檻）。
     *
     * @param  array<int, string>  $downlines
     * @return array<int, string>
     */
    private function buildDepositMessage(
        string $referralCode,
        string $bonus,
        int $currentCount,
        int $targetCount,
        array $downlines,
        int $depositThreshold,
    ): array {
        $downlinesText = $downlines === [] ? '-' : implode(', ', $downlines);

        $messages = [
            'Referral Code: ' . $referralCode,
            'Deposit Referral Bonus: ' . $bonus,
            'Progress: ' . $currentCount . '/' . $targetCount,
            'Minimum Deposit: ' . $depositThreshold . ' points',
            'Deposited Downlines: ' . $downlinesText,
        ];

        if (Common::numCompare((string) $currentCount, (string) $targetCount) >= 0) {
            $messages[] = 'Bonus of ' . $bonus . ' reached!';
        } else {
            $remaining = $targetCount - $currentCount;
            $messages[] = 'Complete ' . $remaining
                . ' more deposit referral (min ' . $depositThreshold . ' points each) to get bonus of ' . $bonus . '!';
        }

        return $messages;
    }
}
