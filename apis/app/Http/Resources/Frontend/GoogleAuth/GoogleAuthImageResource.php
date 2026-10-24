<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\GoogleAuth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoogleAuthImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'image' => $this['image'],
            'secret' => $this['secret'],
        ];
    }
}
