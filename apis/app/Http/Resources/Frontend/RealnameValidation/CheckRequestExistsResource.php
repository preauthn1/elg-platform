<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\RealnameValidation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckRequestExistsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'is_validated' => $this['is_validated'],
            'is_exists' => $this['is_exists'],
            'fields' => (object) $this['fields'],
        ];
    }
}
