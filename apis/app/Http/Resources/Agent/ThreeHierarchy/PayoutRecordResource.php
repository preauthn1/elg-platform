<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\ThreeHierarchy;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * 三層代派發紀錄主表列 工單4199
 *
 * 一列＝受益人（會員或代理）× 結算日的返利彙總。
 */
class PayoutRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var string 身分：member=會員、agent=代理
             *
             * @example "member"
             */
            'identity' => $this->identity,
            /**
             * @var int 受益人 id（下鑽明細用；member_id 或 agent_id 依身分）
             *
             * @example 661824
             */
            'target_id' => (int) $this->target_id,
            /**
             * @var string 帳號
             *
             * @example "vwuser001"
             */
            'username' => $this->username,
            /**
             * @var string 結算期間（該批返利的統計基準日）
             *
             * @example "2026-08-30"
             */
            'summary_time' => Carbon::parse($this->summary_time)->format('Y-m-d'),
            /**
             * @var string 派發時間（GMT+8；SP 補跑時同結算日可能出現不同派發時間）
             *
             * @example "2026-08-31 14:10:12"
             */
            'created_at' => Carbon::parse($this->created_at)->addHours(8)->toDateTimeString(),
            /**
             * @var string|null 計算基數＝投注額合計（自己＋下層＋下下層）；混到輸分佔成資料時回 null，前端顯示「混合，見明細」
             *
             * @example "1695.7852"
             */
            'calc_base' => (int) $this->lose_rows > 0
                ? null
                : Common::numAdd('0', (string) ($this->calc_base ?? 0), 4),
            /**
             * @var string 自身返利
             *
             * @example "0.0800"
             */
            'rebate' => Common::numAdd('0', (string) ($this->rebate ?? 0), 4),
            /**
             * @var string 下層返利
             *
             * @example "1.9299"
             */
            'down_rebate' => Common::numAdd('0', (string) ($this->down_rebate ?? 0), 4),
            /**
             * @var string 下下層返利
             *
             * @example "0.0000"
             */
            'down2_rebate' => Common::numAdd('0', (string) ($this->down2_rebate ?? 0), 4),
            /**
             * @var string 返利合計
             *
             * @example "2.0099"
             */
            'reward' => Common::numAdd('0', (string) ($this->reward ?? 0), 4),
            /**
             * @var int|null 提現單號（會員列：該批結算後最早一筆返利入帳的 withdraw_logs.id，與提現紀錄頁的單號一致；可能涵蓋多個結算日的合併派發。未配到或代理列為 null）
             *
             * @example 671
             */
            'withdraw_log_id' => $this->withdraw_log_id,
        ];
    }
}
