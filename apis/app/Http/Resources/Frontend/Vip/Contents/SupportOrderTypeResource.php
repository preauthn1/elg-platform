<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vip\Contents;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportOrderTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this['name'],
        ];
    }
}
