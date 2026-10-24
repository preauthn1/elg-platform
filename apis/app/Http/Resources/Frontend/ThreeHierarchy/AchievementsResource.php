<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\ThreeHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AchievementsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => \Carbon\Carbon::parse($this->summary_time)->toFrontendTime()->format('m/d'),
            'rebate_type' => $this->rebate_type,
            'username' => $this->fromMember->username,
            'vendor' => $this->vendor->name,
            'game_type' => $this->gameType->name,
            'bet' => $this->bet,
            'reward' => $this->reward,
        ];
    }
}
