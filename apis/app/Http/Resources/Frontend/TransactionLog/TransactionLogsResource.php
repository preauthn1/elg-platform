<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\TransactionLog;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionLogsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $point = match ($this->type) {
            1, 5, 16 => $this->point,
            default => $this->point + $this->tax,
        };

        return [
            'is_rolling_chip' => $this->is_rolling_chip,
            'created_at' => $this->created_at->toFrontendTime()->toDateTimeString(),
            'type' => $this->type,
            'point' => Common::numFloor($point, 4),
            'currency' => '', // 平台幣, DB 沒有存平台的幣別, 前端不會使用這欄位
            'vendor_image' => $this->vendor_custom_image ?? $this->vendor_image ?? null,
            'vendor_name' => $this->vendor_custom_name ?? $this->vendor_name ?? null,
        ];
    }
}
