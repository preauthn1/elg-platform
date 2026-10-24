<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Marquee;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MarqueeWinPointRecord */
class WinPointsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->total_bet_record_id,
            'win' => Common::numFloor($this->win, 2),
            'username' => $this->member->nickname ?? '',
            'game_name' => $this->game->gameNames->first()?->name ?? $this->game->name,
            'game_id' => $this->game_id,
            'vendor_id' => $this->vendor_id,
            'image' => $this->game->image ?? '',
        ];
    }
}
