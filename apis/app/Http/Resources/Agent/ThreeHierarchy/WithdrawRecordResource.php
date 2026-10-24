<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\ThreeHierarchy;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 三層代提現紀錄列 工單4214
 *
 * 一列＝一次返利實際入帳（排程自動派發或會員前台申請）。
 */
class WithdrawRecordResource extends JsonResource
{
    /** 自動派發排程的寫入者 id（SP_payout_three_hierarchy_bonus 寫死 51） */
    private const ADMIN_SCHEDULE = 51;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 提現單號（UI 全體系以此 id 作為單號，非 order_no 欄）
             *
             * @example 672
             */
            'id' => (int) $this->id,
            /**
             * @var string 會員帳號
             *
             * @example "QHY8572"
             */
            'username' => $this->username,
            /**
             * @var string 來源：schedule=自動派發（admin=51）、manual=會員申請（admin=0）
             *
             * @example "schedule"
             */
            'source' => (int) $this->admin === self::ADMIN_SCHEDULE ? 'schedule' : 'manual',
            /**
             * @var int 獎勵形式：1=真金、2=禮碼
             *
             * @example 1
             */
            'type' => (int) $this->type,
            /**
             * @var string 提現獎勵
             *
             * @example "40.00"
             */
            'withdraw_point' => Common::numAdd('0', (string) ($this->withdraw_point ?? 0), 2),
            /**
             * @var string 剩餘獎勵（自動派發全額派完恆為 0，是正確值；會員申請部分提領時為非 0）
             *
             * @example "0.00"
             */
            'remaining_point' => Common::numAdd('0', (string) ($this->remaining_point ?? 0), 2),
            /**
             * @var int 狀態：1=已入帳、0=失敗（兩寫入點皆寫死 1，失敗實際不會出現）
             *
             * @example 1
             */
            'status' => (int) $this->status,
            /**
             * @var string 申請時間（GMT+8）
             *
             * @example "2026-08-29 15:30:00"
             */
            'created_at' => (string) $this->created_at,
        ];
    }
}
