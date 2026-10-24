<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\ThreeHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RuleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->vendor_name,
            'vendor_image' => $this->vendor_image,
            'game_type_name' => $this->game_type_name,
            'game_type_id' => $this->game_type_id,
            'type' => $this->type,
            'proportion_1' => $this->proportion_1,
            'proportion_2' => $this->proportion_2,
            'proportion_3' => $this->proportion_3,
        ];
    }
}
