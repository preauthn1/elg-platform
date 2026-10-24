<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\MemberTeam;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'directPlayerCount' => $this['directPlayerCount'],
            'addedToday' => $this['addedToday'],
            'addedTodayGroupMember' => $this['addedTodayGroupMember'],
            'totalGroupMember' => $this['totalGroupMember'],
            'todayCommission' => $this['todayCommission'],
            'is_payout' => $this['is_payout'],
            'code' => $this['code'],
            'link' => $this['link'],
        ];
    }
}
