<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\UnlimitHierarchy;

use App\Helpers\Common;
use App\Models\UnlimitBonusReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UnlimitBonusReport */
class RewardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'date' => $this->created_at->toFrontendTime()->format('Y-m-d H:i:s'),
            'totalContribution' => Common::numAdd($this->performance_direct, $this->performance_team, 2),
            'teamContribution' => Common::numFloor($this->performance_team, 2),
            'memberContribution' => Common::numFloor($this->performance_direct, 2),
            'reward' => Common::numAdd($this->bonus_direct, $this->bonus_team, 2),
            'flow' => Common::numFloor($this->performance, 2),
            'itself_reward' => Common::numFloor($this->bonus_itself, 2),
            'type' => __('param.issued'),
            'memo' => $this->correction === 1 ? 'Retroactive Reward\nfor ' . $this->memo : '',
        ];
    }
}
