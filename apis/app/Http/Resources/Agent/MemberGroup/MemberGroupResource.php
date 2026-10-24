<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\MemberGroup;

use App\Models\MemberGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MemberGroup */
class MemberGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 群組編號 */
            'id' => $this->id,
            /** @var int 擁有者代理編號 */
            'agent_id' => $this->agent_id,
            /** @var string|null 擁有者代理帳號 */
            'agent_name' => $this->whenLoaded('agent', fn () => $this->agent?->username),
            /** @var string 群組名稱 */
            'name' => $this->name,
            /** @var string|null 內部備註 */
            'remark' => $this->remark,
            /** @var int 排序 */
            'sequence' => $this->sequence,
            /** @var int 1:啟用 0:停用 */
            'status' => $this->status,
            /** @var int 預設群組 */
            'default_setting' => $this->default_setting,
            /** @var int 自助存款帳號白名單開關 */
            'bank_whitelist_enabled' => $this->bank_whitelist_enabled,
            /** @var string 存款單筆下限(-1:不限制) */
            'deposit_min' => $this->deposit_min,
            /** @var string 存款單筆上限(-1:不限制) */
            'deposit_max' => $this->deposit_max,
            /** @var int 存款每日次數(-1:不限制) */
            'deposit_daily_count' => $this->deposit_daily_count,
            /** @var string 存款每日金額(-1:不限制) */
            'deposit_daily_amount' => $this->deposit_daily_amount,
            /** @var string 提款單筆下限(-1:不限制) */
            'withdraw_min' => $this->withdraw_min,
            /** @var string 提款單筆上限(-1:不限制) */
            'withdraw_max' => $this->withdraw_max,
            /** @var int 提款每日次數(-1:不限制) */
            'withdraw_daily_count' => $this->withdraw_daily_count,
            /** @var string 提款每日金額(-1:不限制) */
            'withdraw_daily_amount' => $this->withdraw_daily_amount,
            /** @var array|null 平台手續費階梯 [{from,type,value}]（type 0:固定 1:百分比；null:未啟用，沿用銀行） */
            'withdraw_fee_tiers' => $this->withdrawFeeTiers() ?: null,
            /** @var string 轉帳單筆下限(-1:不限制) */
            'transfer_min' => $this->transfer_min,
            /** @var string 轉帳單筆上限(-1:不限制) */
            'transfer_max' => $this->transfer_max,
            /** @var int 轉帳每日次數(-1:不限制) */
            'transfer_daily_count' => $this->transfer_daily_count,
            /** @var string 轉帳每日金額(-1:不限制) */
            'transfer_daily_amount' => $this->transfer_daily_amount,
            /** @var int 成員數 */
            'member_count' => $this->whenCounted('members'),
            /** @var int[] 已勾選的自助存款帳號 id(詳情才帶) */
            'bank_account_ids' => $this->whenLoaded('bankAccounts', fn () => $this->bankAccounts->pluck('id')),
        ];
    }
}
