<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\MemberTeam;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AchievementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => AchievementDataResource::collection($this['data']),
            'total' => [
                'totalAchievement' => $this['total']->totalAchievement,
                'directPlayersAchievement' => $this['total']->directPlayersAchievement,
                'teamAchievement' => $this['total']->teamAchievement,
            ],
        ];
    }
}
