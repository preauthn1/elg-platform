<?php

namespace App\Repositories;

use DB;
use App\Models\MemberAccessToken;

class MemberRepository
{   
    public function GetAgentInfo($member_id)
    {     
         $query = DB::table("members as m") 
            ->join('agents as a', 'm.parent', 'a.id')
            ->select(
                "a.id as agent_id" , 
                "a.username as agent_username" , 
                "a.customized_setting_group_id" ,
                "a.cash_flow_group_id",
                "a.sms_gateway_group_id",
                "a.chat_group_id"
            )
            ->first();

        return $query;
    }

}