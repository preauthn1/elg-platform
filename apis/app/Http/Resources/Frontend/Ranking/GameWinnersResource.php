<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Ranking;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameWinnersResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->username,
            'image' => $this->image,
            'total_win_lose' => $this->total_win_lose,
            'vendor_name' => $this->vendor_name,
            'rank' => $this->rank,
        ];
    }
}
