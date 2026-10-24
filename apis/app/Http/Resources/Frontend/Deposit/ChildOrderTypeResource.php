<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Deposit;

use App\Http\Resources\Frontend\Deposit\Traits\HasOrderTypeInfoParser;
use App\Models\AgentCashFlowOrderType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentCashFlowOrderType
 */
class ChildOrderTypeResource extends JsonResource
{
    use HasOrderTypeInfoParser;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $orderType = $this->orderType;
        $info = $this->parseOrderTypeInfo($orderType->info ?? null);

        return [
            'id' => $this->order_type_id,
            // 沿用舊行為：子項（choose_bank 銀行）顯示 order_types.name 原文，不走多語系
            'name' => $orderType->name ?? '',
            'order_type_info' => $info ? json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'options' => $info['data'] ?? null,
            'image' => $orderType->image_path ?? '',
            'min_amount' => $this->min_amount ?? 0,
            'max_amount' => $this->max_amount ?? 0,
        ];
    }
}
