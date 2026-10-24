<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\UnlimitHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'level' => $this->level,
            'minAmount' => $this->minAmount,
            'maxAmount' => $this->maxAmount,
            'bounsAmount' => $this->bounsAmount,
            'rebateRate' => $this->rebateRate,
        ];
    }
}
