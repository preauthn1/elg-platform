<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Member;
use App\Models\MemberAgent;

class MemberAgentService
{
    public function getMemberAgent(Member $member): MemberAgent
    {
        return MemberAgent::firstOrCreate(
            [
                'member_id' => $member->id,
            ],
            [
                'parent_id' => $member->agent->id,
                'agent_mode' => 1,
                'agent_level' => 1,
                'agent_relation' => "0,{$member->id},",
            ]
        );
    }
}
