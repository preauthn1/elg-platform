<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vip;

use App\Http\Resources\Frontend\Vip\Contents\RebateProportionResource;
use App\Http\Resources\Frontend\Vip\Contents\RewardResource;
use App\Http\Resources\Frontend\Vip\Contents\SupportOrderTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VipContentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level,
            'name' => $this->name,
            'target_amount' => $this->target_amount,
            'keep_amount' => $this->keep_amount,
            'withdrawal_times_limit' => $this->withdrawal_times_limit,
            'image' => $this->image,
            'withdrawal_amount_limit' => $this->withdrawal_amount_limit,
            'vip_channel' => $this->vip_channel,

            'reward' => RewardResource::collection($this->reward),
            'support_order_type' => SupportOrderTypeResource::collection($this->support_order_type),
            'rebate_proportion' => RebateProportionResource::collection($this->rebate_proportion),
        ];
    }
}
