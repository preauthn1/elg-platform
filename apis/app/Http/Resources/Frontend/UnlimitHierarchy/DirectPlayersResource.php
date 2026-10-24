<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\UnlimitHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DirectPlayersResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'member_id' => $this->member_id,
            'member' => $this->member,
            'teamContribution' => $this->teamContribution,
            'memberContribution' => $this->memberContribution,
            'teamCount' => $this->teamCount,
            'memberCount' => $this->memberCount,
            'memberType' => $this->memberType,
        ];
    }
}
