<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\MemberTeam;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentAchievementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**  本期直屬下級業績 (只有下層)  */
            'directPlayersAchievement' => $this['directPlayersAchievement'] ?? 0,

            /**  本期團隊業績 (不包含自己)  */
            'teamAchievement' => $this['teamAchievement'] ?? 0,

            /**  本期總業績 (包含自己)  */
            'totalAchievement' => $this['totalAchievement'] ?? 0,

            /**  上期傭金  */
            'lastpPeriodCommission' => $this['lastpPeriodCommission'] ?? 0,

            /**  歷史總傭金 下午三點才會更新  */
            'historicalTotalCommission' => $this['historicalTotalCommission'] ?? 0,
        ];
    }
}
