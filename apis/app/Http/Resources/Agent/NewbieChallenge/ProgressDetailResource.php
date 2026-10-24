<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int newbie_challenge_progress.id
             *
             * @example 1
             */
            'id' => $this->id,
            /**
             * @var int newbie_challenge_configs.id
             *
             * @example 1
             */
            'config_id' => $this->config_id,
            /**
             * @var int members.id
             *
             * @example 12345
             */
            'member_id' => $this->member_id,
            /**
             * @var string 玩家帳號
             *
             * @example "vwtest001"
             */
            'member_username' => $this->member->username,
            /**
             * @var int 狀態，見 NewbieChallengeStageEnum
             *
             * @example 1
             */
            'stage' => $this->stage->value,
            /**
             * @var string 領取的活動金快照
             *
             * @example "88.0000"
             */
            'activity_reward' => $this->activity_reward,
            /**
             * @var array|null 第二階段挑戰流水(flow_limit/reach/status)
             */
            'challenge_flow' => $this->challengeFlowLimit === null ? null : [
                'flow_limit' => $this->challengeFlowLimit->flow_limit,
                'reach' => $this->challengeFlowLimit->reach,
                'status' => $this->challengeFlowLimit->status,
            ],
            /**
             * @var string|null 第三階段算出的可核銷彩金上限
             *
             * @example null
             */
            'unlocked_bonus_amount' => $this->unlocked_bonus_amount,
            /**
             * @var string|null 彩金領取資格48hr倒數到期時間
             *
             * @example null
             */
            'bonus_window_expire_at' => $this->bonus_window_expire_at?->toBackendTime()->toDateTimeString(),
            /**
             * @var bool 彩金領取視窗是否已過期；純顯示用旗標，DB 的 stage 不會自動轉態，
             *           跟前端 App 判斷「已失效」用同一套邏輯（stage 仍為 BONUS_PENDING 但視窗已過）
             *
             * @example false
             */
            'is_bonus_window_expired' => $this->stage === NewbieChallengeStageEnum::BONUS_PENDING
                && $this->bonus_window_expire_at !== null
                && now()->greaterThan($this->bonus_window_expire_at),
            /**
             * @var array|null 第四階段彩金流水(flow_limit/reach/status)
             */
            'bonus_flow' => $this->bonusFlowLimit === null ? null : [
                'flow_limit' => $this->bonusFlowLimit->flow_limit,
                'reach' => $this->bonusFlowLimit->reach,
                'status' => $this->bonusFlowLimit->status,
            ],
            /**
             * @var array|null 首筆充值核銷彩金紀錄，尚未核銷（confirm-deposit 還沒成功過）則為 null
             */
            'bonus_claim' => $this->claimed_bonus_amount === null ? null : [
                'deposit_order_id' => $this->deposit_order_id,
                'deposit_transaction_log_id' => $this->deposit_transaction_log_id,
                'deposit_amount' => $this->deposit_amount,
                'claimed_bonus_amount' => $this->claimed_bonus_amount,
            ],
            /**
             * @var string 累積入金本金（純顯示用，不建立流水鎖）
             *
             * @example "0.0000"
             */
            'principal_total' => $this->principal_total,
            /**
             * @var string|null 領取活動金時間
             *
             * @example "2026-08-13 06:15:00"
             */
            'joined_at' => $this->joined_at?->toBackendTime()->toDateTimeString(),
            /**
             * @var string|null 挑戰流水達標時間
             *
             * @example null
             */
            'challenge_completed_at' => $this->challenge_completed_at?->toBackendTime()->toDateTimeString(),
            /**
             * @var string|null 彩金流水達標撥款時間
             *
             * @example null
             */
            'bonus_completed_at' => $this->bonus_completed_at?->toBackendTime()->toDateTimeString(),
            /**
             * @var string|null 本金解鎖時間
             *
             * @example null
             */
            'principal_unlocked_at' => $this->principal_unlocked_at?->toBackendTime()->toDateTimeString(),
            /**
             * @var string|null 放棄活動時間
             *
             * @example null
             */
            'quit_at' => $this->quit_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
