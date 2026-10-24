<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\NewbieChallenge;

use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewbieChallengeProgressResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $flowTarget = $this->challengeFlowLimit?->flow_limit;
        $flowReach = $this->challengeFlowLimit?->reach ?? '0';
        // 顯示用：捨去(非四捨五入)到小數2位，百分比計算仍用上面未捨去的 $flowReach
        $flowReachDisplay = Common::numAdd($flowReach, '0', 2);

        $bonusFlowTarget = $this->bonusFlowLimit?->flow_limit;
        $bonusFlowReach = $this->bonusFlowLimit?->reach ?? '0';
        $bonusFlowReachDisplay = Common::numAdd($bonusFlowReach, '0', 2);

        return [
            /** @var int 狀態，見 NewbieChallengeStageEnum */
            'stage' => $this->stage->value,
            /** @var string 第一階段領取的活動金 */
            'activity_reward' => $this->activity_reward,
            /** @var string|null 第二階段挑戰流水目標 */
            'challenge_flow_target' => $flowTarget,
            /** @var string|null 第二階段挑戰流水已完成；捨去(非四捨五入)到小數2位 */
            'challenge_flow_reach' => $flowTarget !== null ? $flowReachDisplay : null,
            /** @var string|null 第二階段挑戰流水剩餘（目標減捨去後的完成流水）；後台已取消稽核則視同已達標，固定回 0 */
            'challenge_flow_remaining' => $flowTarget !== null
                ? ($this->isChallengeFlowSatisfied ? '0' : max('0', Common::numSub($flowTarget, $flowReachDisplay, 2)))
                : null,
            /** @var float|null 第二階段挑戰流水完成百分比(0~100)；後台已取消稽核則視同已達標，固定回 100 */
            'challenge_flow_percentage' => $flowTarget !== null && Common::numCompare($flowTarget, '0') > 0
                ? ($this->isChallengeFlowSatisfied ? 100.0 : min(100.0, (float) Common::numMul(Common::numDiv($flowReach, $flowTarget, 6), '100', 2)))
                : null,
            /** @var bool 第二階段挑戰流水是否已滿足放行條件（達標，或後台已取消稽核），可以按按鈕進入第三階段(彩金解鎖) */
            'can_complete_challenge' => $this->stage === NewbieChallengeStageEnum::CHALLENGING
                && $this->isChallengeFlowSatisfied,
            /** @var string|null 第三階段算出的可核銷彩金上限(10~1888)，只是資格額度，不代表玩家最終實際拿到多少 */
            'unlocked_bonus_amount' => $this->unlocked_bonus_amount,
            /** @var string|null 第三階段彩金領取資格到期時間(48hr倒數) */
            'bonus_window_expire_at' => $this->bonus_window_expire_at?->toFrontendTime()->toDateTimeString(),
            /** @var bool 第三階段是否已有符合資格(彩金視窗內、達最低解鎖門檻)的已上分訂單，可以按「下一步」呼叫 confirm-deposit */
            'can_confirm_deposit' => $this->canConfirmDeposit,
            /** @var string|null 第四階段核銷的彩金金額，流水跑完後會撥入主錢包；
             *                    尚未核銷（confirm-deposit 還沒成功過）則為 null；流水完成前後都可以用這個顯示金額，
             *                    有沒有真的撥款完成看 stage（BONUS_COMPLETED 才代表已撥款） */
            'pending_bonus_amount' => $this->claimed_bonus_amount,
            /** @var string 彩金流水倍數（第四階段流水目標 = 儲值金額 x 此倍數），活動規則固定值 */
            'bonus_flow_multiple' => $this->config->bonus_flow_multiple,
            /** @var string|null 第四階段彩金流水目標 */
            'bonus_flow_target' => $bonusFlowTarget,
            /** @var string|null 第四階段彩金流水已完成；捨去(非四捨五入)到小數2位 */
            'bonus_flow_reach' => $bonusFlowTarget !== null ? $bonusFlowReachDisplay : null,
            /** @var string|null 第四階段彩金流水剩餘（目標減捨去後的完成流水）；後台已取消稽核則視同已達標，固定回 0 */
            'bonus_flow_remaining' => $bonusFlowTarget !== null
                ? ($this->isBonusFlowSatisfied ? '0' : max('0', Common::numSub($bonusFlowTarget, $bonusFlowReachDisplay, 2)))
                : null,
            /** @var float|null 第四階段彩金流水完成百分比(0~100)；後台已取消稽核則視同已達標，固定回 100 */
            'bonus_flow_percentage' => $bonusFlowTarget !== null && Common::numCompare($bonusFlowTarget, '0') > 0
                ? ($this->isBonusFlowSatisfied ? 100.0 : min(100.0, (float) Common::numMul(Common::numDiv($bonusFlowReach, $bonusFlowTarget, 6), '100', 2)))
                : null,
            /** @var bool 第四階段彩金流水是否已滿足放行條件（達標，或後台已取消稽核），可以按按鈕撥款進入第五階段(本金鎖定) */
            'can_claim_bonus' => $this->stage === NewbieChallengeStageEnum::BONUS_WAGERING
                && $this->isBonusFlowSatisfied,
            /** @var string 第五階段鎖定本金（第四階段起累積入金本金，本金流水完成前禁止提款/轉帳） */
            'principal_total' => $this->principal_total,
            /** @var string|null 第一階段領取活動金時間 */
            'joined_at' => $this->joined_at?->toFrontendTime()->toDateTimeString(),
            /** @var string 活動錢包餘額（第二階段挑戰流水期間累積，第三階段解鎖後歸零） */
            'event_wallet_balance' => $this->eventWalletBalance,
            /** @var string 主錢包餘額 */
            'main_wallet_balance' => $this->mainWalletBalance,
        ];
    }
}
