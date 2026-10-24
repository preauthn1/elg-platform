<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Report;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SR12Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'game_no' => $this->reference_no,
            'member_username' => $this->member->username,
            'agent_username' => $this->member->agent->username,
            'vendor_name' => $this->vendor->name,
        ];
    }
}
