<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\GgrPlan;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GgrPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 方案編號 */
            'id' => $this->id,
            /** @var string GGR標題 */
            'name' => $this->name,
            /** @var int 結算週期 1:每日 2:每週 3:每月（設定端視角：有待生效變更時回待生效值，當期引擎仍照現行值跑完） */
            'settlement_cycle' => $this->pending_settlement_cycle ?? $this->settlement_cycle,
            /** @var int 代理群組 agent_groups.id */
            'agent_group_id' => $this->agent_group_id,
            /** @var string 開始日期 Y-m-d */
            'start_date' => $this->start_date,
            /** @var int 发扬 1:負數GGR用原始數據結轉下期 */
            'carry_negative' => $this->carry_negative,
            /** @var string 管理費% */
            'admin_fee_rate' => $this->admin_fee_rate,
            /** @var string 手續費（固定金額） */
            'handling_fee' => $this->handling_fee,
            /** @var string GGR百分比% */
            'ggr_rate' => $this->ggr_rate,
            /** @var string 獎金百分比% */
            'bonus_rate' => $this->bonus_rate,
            /** @var int 銀行帳號唯一限制 */
            'kyc_unique_bank_account' => $this->kyc_unique_bank_account,
            /** @var int 手機號碼唯一限制 */
            'kyc_unique_contact_no' => $this->kyc_unique_contact_no,
            /** @var int 信箱唯一限制 */
            'kyc_unique_email' => $this->kyc_unique_email,
            /** @var int IP唯一限制 */
            'kyc_unique_ip' => $this->kyc_unique_ip,
            /** @var int 姓名唯一限制 */
            'kyc_unique_name' => $this->kyc_unique_name,
            /** @var int 違規次數 */
            'kyc_violate_count' => $this->kyc_violate_count,
            /** @var int 成員下線設置：遊戲商設定 id */
            'downline_vendor_setting_id' => $this->downline_vendor_setting_id,
            /** @var string|null 成員下線設置：遊戲商設定名稱（view 載入） */
            'downline_vendor_setting_name' => $this->whenLoaded('downlineVendorSetting', fn () => $this->downlineVendorSetting?->name),
            /** @var int 成員下線設置：條件設定 id */
            'downline_condition_id' => $this->downline_condition_id,
            /** @var string|null 成員下線設置：條件設定名稱（view 載入） */
            'downline_condition_name' => $this->whenLoaded('downlineCondition', fn () => $this->downlineCondition?->name),
            /** @var int|null 特殊用戶數（list 載入） */
            'special_agents_count' => $this->whenCounted('specialAgents'),
            /** @var array|null 特殊用戶 override 列（view 載入） */
            'special_agents' => $this->whenLoaded('specialAgents', fn () => $this->specialAgents->map(fn ($row) => [
                /** @var int 代理編號 */
                'agent_id' => $row->agent_id,
                /** @var string|null 代理帳號 */
                'agent_username' => $row->agent?->username,
                /** @var int override 的遊戲商設定 id */
                'vendor_setting_id' => $row->vendor_setting_id,
                /** @var string|null override 的遊戲商設定名稱 */
                'vendor_setting_name' => $row->vendorSetting?->name,
                /** @var int override 的條件設定 id */
                'condition_id' => $row->condition_id,
                /** @var string|null override 的條件設定名稱 */
                'condition_name' => $row->condition?->name,
            ])->all()),
            /** @var int 狀態 1:啟用 0:停用 */
            'status' => $this->status,
            /** @var string|null 建立人員帳號 */
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->username),
            /** @var string|null 更新者帳號 */
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->username),
            /** @var string|null 建立時間 */
            'created_at' => $this->created_at?->toBackendTime()->toDateTimeString(),
            /** @var string|null 更新時間 */
            'updated_at' => $this->updated_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
