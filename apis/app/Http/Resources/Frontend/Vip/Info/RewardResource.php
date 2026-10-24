<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vip\Info;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'reward_id' => $this['reward_id'],
            'name' => $this['name'],
            'status' => $this['status'],
            'expired_time' => $this['expired_time'],
        ];
    }
}
