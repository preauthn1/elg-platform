<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Member;

use App\Helpers\Common;
use App\Services\Agent\MemberContactMaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** 時間欄位一律輸出後台時區 GMT+8（DB 存 UTC，target/tickets#4492） */
class MemberActivityFilterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 會員ID
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
             * @var string 手機號碼
             *
             * @example "0912345678"
             */
            'mobile' => MemberContactMaskService::mobile($request, $this->mobile),
            /**
             * @var int 代收次數
             *
             * @example 3
             */
            'deposit_count' => (int) ($this->deposit_count ?? 0),
            /**
             * @var string 代收總額
             *
             * @example "1000.0000"
             */
            'deposit_amount' => Common::numAdd('0', (string) ($this->deposit_amount ?? 0), 4),
            /**
             * @var int 代付次數
             *
             * @example 1
             */
            'withdraw_count' => (int) ($this->withdraw_count ?? 0),
            /**
             * @var string 代付總額
             *
             * @example "500.0000"
             */
            'withdraw_amount' => Common::numAdd('0', (string) ($this->withdraw_amount ?? 0), 4),
            /**
             * @var string|null 最後代收時間
             *
             * @example "2026-07-20 08:14:24"
             */
            'last_deposit_at' => $this->last_deposit_at ? Carbon::parse($this->last_deposit_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 最後代付時間
             *
             * @example "2026-07-20 09:00:00"
             */
            'last_withdraw_at' => $this->last_withdraw_at ? Carbon::parse($this->last_withdraw_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 最後投注時間
             *
             * @example "2026-07-21 12:00:00"
             */
            'last_bet_at' => $this->last_bet_at ? Carbon::parse($this->last_bet_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 最後活動時間
             *
             * @example "2026-07-21 12:30:00"
             */
            'last_activity_at' => $this->last_activity_at ? Carbon::parse($this->last_activity_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 最後登入時間
             *
             * @example "2026-07-21 10:00:00"
             */
            'last_login' => $this->last_login ? Carbon::parse($this->last_login)->toBackendTime()->toDateTimeString() : null,
        ];
    }
}
