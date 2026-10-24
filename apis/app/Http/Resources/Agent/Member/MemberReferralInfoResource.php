<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberReferralInfoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 會員系統編號
             *
             * @example 12345
             */
            'member_id' => (int) $this->member_id,
            /**
             * @var string 玩家帳號
             *
             * @example "vwtest001"
             */
            'username' => $this->username,
            /**
             * @var string 上層代理商帳號
             *
             * @example "gfftest"
             */
            'agent_username' => $this->agent_username,
            /**
             * @var int 帳號狀態（0:未認證, 1:正常, 2:鎖定）
             *
             * @example 1
             */
            'status' => (int) $this->status,
            /**
             * @var int 直屬人數（期間內成為直屬的下線數）
             *
             * @example 3
             */
            'direct_count' => (int) $this->direct_count,
            /**
             * @var int 首存人數（期間內完成首儲的直屬下線數）
             *
             * @example 1
             */
            'first_deposit_count' => (int) $this->first_deposit_count,
            /**
             * @var int 存款次數（直屬下線；三方支付＋自助上分）
             *
             * @example 5
             */
            'deposit_count' => (int) $this->deposit_count,
            /**
             * @var string 總儲值金額
             *
             * @example "500.0000"
             */
            'deposit_amount' => (string) $this->deposit_amount,
            /**
             * @var int 提款次數（直屬下線；三方提現＋自助洗分）
             *
             * @example 2
             */
            'withdrawal_count' => (int) $this->withdrawal_count,
            /**
             * @var string 總提款金額
             *
             * @example "200.0000"
             */
            'withdrawal_amount' => (string) $this->withdrawal_amount,
            /**
             * @var int 投注次數（直屬下線）
             *
             * @example 120
             */
            'bet_count' => (int) $this->bet_count,
            /**
             * @var string 總流水（直屬下線有效流水）
             *
             * @example "1500.0000"
             */
            'valid_bet' => (string) $this->valid_bet,
            /**
             * @var string 獎勵金（直屬下線平台獎勵，排除邀請獎勵/客服上分/紅包）
             *
             * @example "30.0000"
             */
            'reward_amount' => (string) $this->reward_amount,
            /**
             * @var string 總推薦獎金（本人期間內的邀請活動獎勵）
             *
             * @example "88.0000"
             */
            'referral_reward_amount' => (string) $this->referral_reward_amount,
        ];
    }
}
