<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\MemberTransfer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetVendorBalanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** 遊戲商餘額, 已轉換為本地錢包匯率 */
            'vendor_balance' => $this['vendor_balance'],
        ];
    }
}
