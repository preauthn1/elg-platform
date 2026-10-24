<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Enums\LivechatBotVipRewardEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberVip;
use App\Models\VipContent;
use App\Models\VipContentReward;
use Illuminate\Support\Collection;

/**
 * Livechat Bot 取得會員 VIP 設定。
 *
 * 透過 MemberVip → VipContent 取得目前等級與所有 reward（vip_content_rewards）。
 * 並查詢同 agent_id、level 大於目前等級的最近一級，作為「下一級」資訊來源。
 */
class GetVipSettingService
{
    /**
     * 數值（output）統一使用 4 位小數。
     */
    private const int OUTPUT_SCALE = 4;

    /**
     * message 顯示用 2 位小數。
     */
    private const int MESSAGE_SCALE = 2;

    private const string ZERO_DECIMAL = '0.0000';

    public function __construct(
        protected LivechatBotContext $context,
        protected MemberAgentGuard $guard,
    ) {}

    /**
     * 取得會員 VIP 設定。
     *
     * @return array<string, mixed>
     */
    public function getVipSetting(string $username): array
    {
        $member = $this->context->findMemberByUsername($username);

        if ($member === null) {
            throw new ApiException('Member not found', 404);
        }

        /** @var MemberVip|null $memberVip */
        $memberVip = MemberVip::query()
            ->where('member_id', $member->id)
            ->with(['vipContent.vipContentRewards.vipReward'])
            ->first();

        if ($memberVip !== null && $memberVip->vipContent !== null) {
            $vipContent = $memberVip->vipContent;
            $depositAmount = (string) $memberVip->deposit_amount;
        } else {
            // 對齊舊系統：會員從未首充、無 member_vips 時，降級到 agent 階層中最低級的 VipContent。
            $vipContent = $this->resolveFallbackVipContent($member);

            if ($vipContent === null) {
                throw new ApiException('Member VIP not found', 404);
            }

            $depositAmount = '0';
        }

        /** @var Collection<int, VipContentReward> $rewards */
        $rewards = $vipContent->vipContentRewards;
        $rewardsByRewardId = $rewards->keyBy('vip_reward_id');

        $nextVipContent = $this->findNextVipContent($vipContent);
        $nextUpgradeBonus = $this->resolveNextUpgradeBonus($nextVipContent);
        $upgradeProgress = $this->resolveUpgradeProgress(
            depositAmount: $depositAmount,
            nextVipContent: $nextVipContent,
        );

        $vipLevel = 'VIP' . $vipContent->level;
        $name = (string) ($vipContent->name ?? '');

        $vipDailyBonus = $this->resolveBonus($rewardsByRewardId, LivechatBotVipRewardEnum::DAILY_REBATE);
        $vipWeeklyBonus = $this->resolveBonus($rewardsByRewardId, LivechatBotVipRewardEnum::WEEKLY_GIFT);
        $vipMonthlyBonus = $this->resolveBonus($rewardsByRewardId, LivechatBotVipRewardEnum::MONTHLY_GIFT);
        $vipBirthdayBonus = $this->resolveBonus($rewardsByRewardId, LivechatBotVipRewardEnum::BIRTHDAY);
        $vipHolidayBonus = $this->resolveBonus($rewardsByRewardId, LivechatBotVipRewardEnum::COMMEMORATE);
        $vipWeeklyAidBonus = $this->resolveBonus($rewardsByRewardId, LivechatBotVipRewardEnum::WEEKLY_RESCUE);

        // 規格未提供對應 vip_reward_id，恆為 "0.0000"
        $vipDailyAidBonus = self::ZERO_DECIMAL;
        $vipMonthlyAidBonus = self::ZERO_DECIMAL;

        $upgradePoints = Common::numAdd('0', (string) $vipContent->target_amount, self::OUTPUT_SCALE);
        $maintainPoints = Common::numAdd('0', (string) $vipContent->keep_amount, self::OUTPUT_SCALE);
        $url = (string) ($this->context->vipUrl() ?? '');

        $output = [
            'id' => $vipContent->id,
            'vipLevel' => $vipLevel,
            'name' => $name,
            'vipDailyBonus' => $vipDailyBonus,
            'vipMonthlyBonus' => $vipMonthlyBonus,
            'vipWeeklyBonus' => $vipWeeklyBonus,
            'vipDailyAidBonus' => $vipDailyAidBonus,
            'vipWeeklyAidBonus' => $vipWeeklyAidBonus,
            'vipMonthlyAidBonus' => $vipMonthlyAidBonus,
            'vipBirthdayBonus' => $vipBirthdayBonus,
            'upgradePoints' => $upgradePoints,
            'vipUpgradeBonus' => $nextUpgradeBonus,
            'maintainPoints' => $maintainPoints,
            'vipHolidayBonus' => $vipHolidayBonus,
            'upgradeProgress' => $upgradeProgress,
            'url' => $url,
        ];

        $output['message'] = $this->buildMessage($output);

        return $output;
    }

    /**
     * 降級解析：會員無 member_vips 時，在會員 agent 階層（自身＋所有祖先）範圍內
     * 找出最低級的 VipContent 作為「目前等級」。
     *
     * vip_contents.agent_id 不一定等於 member 直屬 parent agent，VIP 階梯可能掛在
     * hierarchy_path 上的祖先 agent；故必須以整條階層為查詢範圍。
     *
     * 決定性規則：優先取離 member 最近的祖先 agent（hierarchy_array 越前越近）所擁有
     * 的階梯，同 agent 內取 level 最小者。階層完全無 VipContent 時回 null。
     */
    private function resolveFallbackVipContent(Member $member): ?VipContent
    {
        $agent = $member->agent;
        if ($agent === null) {
            return null;
        }

        // hierarchy_array：由近到遠的 agent id 陣列（含自身），例 "/1/59497/59536" -> [59536, 59497, 1]
        $agentIds = array_map('intval', $agent->hierarchy_array);
        if ($agentIds === []) {
            return null;
        }

        /** @var Collection<int, VipContent> $candidates */
        $candidates = VipContent::query()
            ->whereIn('agent_id', $agentIds)
            ->with(['vipContentRewards.vipReward'])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $candidatesByAgent = $candidates->groupBy('agent_id');

        // 依 hierarchy_array 順序（近到遠）挑第一個有 VipContent 的 agent，其階梯取 level 最小。
        foreach ($agentIds as $agentId) {
            $agentCandidates = $candidatesByAgent->get($agentId);
            if ($agentCandidates !== null && $agentCandidates->isNotEmpty()) {
                return $agentCandidates->sortBy('level')->first();
            }
        }

        return null;
    }

    /**
     * 找下一級 VipContent：同 agent_id、level > current.level，取 level ASC 第一筆。
     */
    private function findNextVipContent(VipContent $current): ?VipContent
    {
        return VipContent::query()
            ->where('agent_id', $current->agent_id)
            ->where('level', '>', $current->level)
            ->with(['vipContentRewards'])
            ->orderBy('level', 'asc')
            ->first();
    }

    /**
     * 取得下一級的「升級獎金」（vip_reward_id = 4）。
     *
     * 下一級不存在或無對應 reward → "0.0000"。
     */
    private function resolveNextUpgradeBonus(?VipContent $nextVipContent): string
    {
        if ($nextVipContent === null) {
            return self::ZERO_DECIMAL;
        }

        $reward = $nextVipContent->vipContentRewards
            ->firstWhere('vip_reward_id', LivechatBotVipRewardEnum::UPGRADE->value);

        if ($reward === null) {
            return self::ZERO_DECIMAL;
        }

        return Common::numAdd('0', (string) $reward->point, self::OUTPUT_SCALE);
    }

    /**
     * 計算升級進度字串。
     *
     * 下一級存在 → "{deposit_amount} / {下一級 target_amount}"
     * 下一級不存在 → "0.0000 / 0.0000"
     */
    private function resolveUpgradeProgress(string $depositAmount, ?VipContent $nextVipContent): string
    {
        if ($nextVipContent === null) {
            return self::ZERO_DECIMAL . ' / ' . self::ZERO_DECIMAL;
        }

        $current = Common::numAdd('0', $depositAmount, self::OUTPUT_SCALE);
        $target = Common::numAdd('0', (string) $nextVipContent->target_amount, self::OUTPUT_SCALE);

        return $current . ' / ' . $target;
    }

    /**
     * 取得指定 vip_reward 的獎金值（4 位小數字串）。
     *
     * 找不到對應 reward 時回 "0.0000"，型別與其他欄位保持一致。
     *
     * @param  Collection<int, VipContentReward>  $rewardsByRewardId  以 vip_reward_id 為 key 的 reward 集合
     */
    private function resolveBonus(Collection $rewardsByRewardId, LivechatBotVipRewardEnum $reward): string
    {
        /** @var VipContentReward|null $vipContentReward */
        $vipContentReward = $rewardsByRewardId->get($reward->value);

        if ($vipContentReward === null) {
            return self::ZERO_DECIMAL;
        }

        return Common::numAdd('0', (string) $vipContentReward->point, self::OUTPUT_SCALE);
    }

    /**
     * 組裝對使用者顯示的 message 陣列。
     *
     * @param  array<string, mixed>  $output
     * @return array<int, string>
     */
    private function buildMessage(array $output): array
    {
        return [
            'Vip Level: ' . $output['vipLevel'],
            'Vip Name: ' . $output['name'],
            'Vip Daily Bonus: ' . Common::numAdd('0', $output['vipDailyBonus'], self::MESSAGE_SCALE),
            'Vip Weekly Bonus: ' . Common::numAdd('0', $output['vipWeeklyBonus'], self::MESSAGE_SCALE),
            'Vip Monthly Bonus: ' . Common::numAdd('0', $output['vipMonthlyBonus'], self::MESSAGE_SCALE),
            'Vip Daily Aid Bonus: ' . Common::numAdd('0', $output['vipDailyAidBonus'], self::MESSAGE_SCALE),
            'Vip Weekly Aid Bonus: ' . Common::numAdd('0', $output['vipWeeklyAidBonus'], self::MESSAGE_SCALE),
            'Vip Monthly Aid Bonus: ' . Common::numAdd('0', $output['vipMonthlyAidBonus'], self::MESSAGE_SCALE),
            'Vip Birthday Bonus: ' . Common::numAdd('0', $output['vipBirthdayBonus'], self::MESSAGE_SCALE),
            'Vip Holiday Bonus: ' . Common::numAdd('0', $output['vipHolidayBonus'], self::MESSAGE_SCALE),
            'Vip Maintain Points: ' . Common::numAdd('0', $output['maintainPoints'], self::MESSAGE_SCALE),
            'Next Level Vip Upgrade Bonus: ' . Common::numAdd('0', $output['vipUpgradeBonus'], self::MESSAGE_SCALE),
            'Vip Upgrade Progress: ' . $output['upgradeProgress'],
            'Vip Setting Refer URL: ' . $output['url'],
        ];
    }
}
