<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Withdrawal;

use App\Models\WithdrawalOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WithdrawalOrder */
/**
 * @knowledge rules/standard-bank-list
 */
class WithdrawalOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 流水號 (非訂單編號) */
            'id' => $this->id,
            /** @var string 建立時間 (GMT+8) */
            'created_at' => $this->created_at->toFrontendTime()->toDateTimeString(),
            /** @var float 提款金額 */
            'amount' => $this->amount,
            /** @var string 訂單狀態 */
            'status' => __('param.withdrawal_status_' . $this->status),
            /** @var string 訂單icon文字 */
            'icon_status' => $this->getIconStatus($this->status),
            /** @var string|null 銀行圖示 */
            'bank_icon' => $this->bank?->image_url,
            /** @var string 銀行名稱 */
            'bank_name' => $this->bank?->names?->first()?->name ?? $this->bank?->name ?? '',
            /** @var string 訂單備註, 看起來固定是AutoWithdrawal, 有必要留著嗎? */
            'remark' => 'AutoWithdrawal',
        ];
    }

    private function getIconStatus(int $status): string
    {
        return match ($status) {
            1 => 'pending',
            2 => 'fail',
            3 => 'pending',
            4 => 'success',
            9 => 'fail',
            21 => 'pending',
            22 => 'pending',
            24 => 'success',
            29 => 'pending',
            default => 'pending',
        };
    }
}
