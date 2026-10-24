<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Tgbot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebAppLoginResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this['token'],
        ];
    }
}
