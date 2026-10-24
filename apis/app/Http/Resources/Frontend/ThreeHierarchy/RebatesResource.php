<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\ThreeHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RebatesResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => \Carbon\Carbon::parse($this->date)->toFrontendTime()->format('Y-m-d H:i:s'),
            'rebate_1' => $this->rebate_1,
            'rebate_2' => $this->rebate_2,
            'rebate_3' => $this->rebate_3,
            'lose_rebate_1' => $this->lose_rebate_1,
            'lose_rebate_2' => $this->lose_rebate_2,
            'lose_rebate_3' => $this->lose_rebate_3,
            'reward' => $this->reward,
        ];
    }
}
