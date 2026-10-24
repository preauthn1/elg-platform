<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Game;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowResource extends JsonResource
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
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->vendor->vendorCustomizedItems->first()->name ?? $this->vendor->name,
            'status' => $this->status,
            'type_id' => $this->type_id,
            'name' => $this->gameNames?->first()?->name ?? $this->name,
            'lottery_pool' => $this->lottery_pool,
            'image' => empty($this->image) ? $this->vendor->image : $this->image, // null or empty string
            'is_new' => $this->vendorInfoGames->first()->is_new,
            'is_hot' => $this->vendorInfoGames->first()->is_hot,
            'join_bet_event' => $this->vendorInfoGames->first()->join_bet_event,
            'join_win_event' => $this->vendorInfoGames->first()->join_win_event,
        ];
    }
}
