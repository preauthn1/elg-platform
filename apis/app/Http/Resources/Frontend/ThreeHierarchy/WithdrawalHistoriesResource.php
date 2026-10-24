<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\ThreeHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawalHistoriesResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => \Carbon\Carbon::parse($this->created_at)->toFrontendTime()->format('Y-m-d H:i:s'),
            'no' => $this->id,
            'amount' => $this->withdraw_point,
            'type' => __('param.issued'),
            'withdrawType' => ($this->type == 1) ? __('param.points') : __('param.rolling_chips'),
            'withdrawAccount' => 0,
        ];
    }
}
