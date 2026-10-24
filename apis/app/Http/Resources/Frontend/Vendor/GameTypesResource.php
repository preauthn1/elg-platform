<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameTypesResource extends JsonResource
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
            'type_id' => $this->type_id,
            'root_type' => $this->root_type,
        ];
    }
}
