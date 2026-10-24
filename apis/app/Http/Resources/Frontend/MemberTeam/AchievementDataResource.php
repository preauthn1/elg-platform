<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\MemberTeam;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AchievementDataResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->game_type,
            'totalAchievement' => $this->totalAchievement,
            'directPlayersAchievement' => $this->directPlayersAchievement,
            'teamAchievement' => $this->teamAchievement,
        ];
    }
}
