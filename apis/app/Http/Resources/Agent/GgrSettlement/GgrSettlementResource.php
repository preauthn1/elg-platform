<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\GgrSettlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GgrSettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 紀錄編號 */
            'id' => $this->id,
            /** @var int 來源方案 id */
            'plan_id' => $this->plan_id,
            /** @var string|null 來源方案名稱（view 載入） */
            'plan_name' => $this->whenLoaded('plan', fn () => $this->plan?->name),
            /** @var int 代理編號 */
            'agent_id' => $this->agent_id,
            /** @var string|null 代理帳號 */
            'agent_username' => $this->whenLoaded('agent', fn () => $this->agent?->username),
            /** @var int 結算週期快照 1:每日 2:每週 3:每月 */
            'settlement_cycle' => $this->settlement_cycle,
            /** @var string 結算區間起 Y-m-d */
            'period_start' => $this->period_start,
            /** @var string 結算區間迄 Y-m-d */
            'period_end' => $this->period_end,
            /** @var string 原始總GGR */
            'ggr_amount' => $this->ggr_amount,
            /** @var string 獎金百分比% */
            'bonus_rate' => $this->bonus_rate,
            /** @var string GGR百分比% */
            'ggr_rate' => $this->ggr_rate,
            /** @var string 條件百分比%（4124 命中階應得%） */
            'condition_rate' => $this->condition_rate,
            /** @var string 管理費% */
            'admin_fee_rate' => $this->admin_fee_rate,
            /** @var string 手續費 */
            'handling_fee' => $this->handling_fee,
            /** @var string 最終金額 */
            'final_amount' => $this->final_amount,
            /** @var string 发扬:上期結轉帶入 */
            'carry_in_amount' => $this->carry_in_amount,
            /** @var string 发扬:本期結轉帶出 */
            'carry_out_amount' => $this->carry_out_amount,
            /** @var int 結轉狀態 1:有結轉 0:無（由 carry_out_amount 推導） */
            'carry' => (float) $this->carry_out_amount === 0.0 ? 0 : 1,
            /** @var array|null 計算明細快照（view 載入；list 不回傳減量） */
            'calc_snapshot' => $this->when($this->relationLoaded('plan'), fn () => $this->calc_snapshot),
            /** @var int 發放狀態 0:未發放 1:已發放 */
            'payout_status' => $this->payout_status,
            /** @var string|null 發放時間（業務時區，與 created_at 同基準） */
            'paid_at' => $this->paid_at?->toBackendTime()->toDateTimeString(),
            /** @var string|null 備註 */
            'remark' => $this->remark,
            /** @var string|null 管理者帳號 */
            'admin_username' => $this->whenLoaded('updater', fn () => $this->updater?->username),
            /** @var string|null 建立時間 */
            'created_at' => $this->created_at?->toBackendTime()->toDateTimeString(),
            /** @var string|null 更新時間 */
            'updated_at' => $this->updated_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
