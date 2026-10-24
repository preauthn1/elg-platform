<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\BetRecord;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccumulatedBetsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'today_accumulated_bet' => $this['today_bet_sum'],
            'yesterday_accumulated_bet' => $this['yesterday_bet_sum'],
        ];
    }
}
