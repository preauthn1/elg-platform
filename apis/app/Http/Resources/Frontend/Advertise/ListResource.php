<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Advertise;

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
            'id' => $this->id,
            'path_id' => $this->path_id,
            'type' => $this->type,
            'game_type_id' => $this->game_type_id,
            'game_id' => $this->game_id,
            'vendor_id' => $this->vendor_id,
            'url' => $this->url,
            'image' => $this->image,
            'image_pc' => $this->image_pc,
            'open_window' => $this->open_window,
        ];
    }
}
