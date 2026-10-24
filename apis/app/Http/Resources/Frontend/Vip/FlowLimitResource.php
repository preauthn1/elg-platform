<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vip;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowLimitResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => Carbon::parse($this->created_at)->toFrontendTime()->format('Y-m-d H:i:s'),
            'amount' => $this->deposit_amount,
            'flow_limit' => $this->flow_limit,
            'finish_flow_limit' => $this->reach,
            'status' => $this->status,
        ];
    }
}
