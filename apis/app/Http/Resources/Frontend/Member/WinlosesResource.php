<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WinlosesResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gameName = $this->game?->gameNames?->first()?->name ?? $this->game?->name ?? 'undefined game';

        return [
            'valid_bet' => $this->valid_bet,
            'win_lose' => $this->win_lose,
            'record_count' => $this->record_count,
            'name' => $gameName,
        ];
    }
}
