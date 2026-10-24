<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ProgressListResource extends JsonResource
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
            'id' => (int) $this->id,
            /**
             * @var int newbie_challenge_configs.id
             *
             * @example 1
             */
            'config_id' => (int) $this->config_id,
            /**
             * @var int members.id
             *
             * @example 12345
             */
            'member_id' => (int) $this->member_id,
            /**
             * @var string 玩家帳號
             *
             * @example "vwtest001"
             */
            'member_username' => $this->member_username,
            /**
             * @var string 代理帳號
             *
             * @example "vwagent001"
             */
            'agent_username' => $this->agent_username,
            /**
             * @var int 狀態，見 NewbieChallengeStageEnum
             *
             * @example 1
             */
            'stage' => (int) $this->stage,
            /**
             * @var string 領取的活動金快照
             *
             * @example "88.0000"
             */
            'activity_reward' => $this->activity_reward,
            /**
             * @var string 活動錢包目前餘額（WalletEnum::CODE_EVENT_WALLET），供後台調整餘額前參考；
             *             會員從未有過活動錢包（member_wallets 無對應列）則回 '0'
             *
             * @example "88.0000"
             */
            'event_wallet_balance' => $this->event_wallet_balance ?? '0',
            /**
             * @var string|null 第三階段算出的可核銷彩金上限
             *
             * @example null
             */
            'unlocked_bonus_amount' => $this->unlocked_bonus_amount,
            /**
             * @var string|null 第四階段核銷的彩金金額（來自 bonus_claim，流水完成前後都可用），
             *                  尚未核銷則為 null；是否已撥款看 stage
             *
             * @example "0.0000"
             */
            'claimed_bonus_amount' => $this->claimed_bonus_amount,
            /**
             * @var string 累積入金本金
             *
             * @example "0.0000"
             */
            'principal_total' => $this->principal_total,
            /**
             * @var string|null 彩金領取資格48hr倒數到期時間
             *
             * @example null
             */
            'bonus_window_expire_at' => $this->bonus_window_expire_at ? Carbon::parse($this->bonus_window_expire_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var bool 彩金領取視窗是否已過期；純顯示用旗標，DB 的 stage 不會自動轉態，
             *           跟前端 App 判斷「已失效」用同一套邏輯（stage 仍為 BONUS_PENDING 但視窗已過）
             *
             * @example false
             */
            'is_bonus_window_expired' => (int) $this->stage === NewbieChallengeStageEnum::BONUS_PENDING->value
                && $this->bonus_window_expire_at !== null
                && now()->greaterThan(Carbon::parse($this->bonus_window_expire_at)),
            /**
             * @var string|null 領取活動金時間
             *
             * @example "2026-08-13 06:15:00"
             */
            'joined_at' => $this->joined_at ? Carbon::parse($this->joined_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 挑戰流水達標時間
             *
             * @example null
             */
            'challenge_completed_at' => $this->challenge_completed_at ? Carbon::parse($this->challenge_completed_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 彩金流水達標撥款時間
             *
             * @example null
             */
            'bonus_completed_at' => $this->bonus_completed_at ? Carbon::parse($this->bonus_completed_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 放棄活動時間
             *
             * @example null
             */
            'quit_at' => $this->quit_at ? Carbon::parse($this->quit_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string 建立時間
             *
             * @example "2026-08-13 06:15:00"
             */
            'created_at' => Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
        ];
    }
}
