<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vip;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => Carbon::parse($this->updated_at)->toFrontendTime()->format('Y-m-d H:i:s'),
            'vip_reward_id' => $this->vipContentReward->vipReward->id,
            'reward_name' => $this->vipContentReward->vipReward->name,
            'point' => $this->point,
        ];
    }
}
