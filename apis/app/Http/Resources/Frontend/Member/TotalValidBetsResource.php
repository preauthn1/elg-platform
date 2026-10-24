<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TotalValidBetsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total_valid_bet' => $this->total_valid_bet,
            'name' => $this->vendorCustomizedItems?->first()->name ?? $this->vendor->name,
            'image' => $this->vendorCustomizedItems?->first()->image ?? $this->vendor->image,
            'summary_time' => \Carbon\Carbon::parse($this->summary_time)->toFrontendTime()->format('Y-m-d H:i:s'),
        ];
    }
}
