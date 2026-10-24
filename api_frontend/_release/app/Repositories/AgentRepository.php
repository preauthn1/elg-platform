<?php

namespace App\Repositories;

use DB;

class AgentRepository
{   	

    public function getHierarchyAgentIDsArray($agent_id)
    {     
        $queryAgent = DB::table("agents")->where('id', $agent_id)
            ->select("hierarchy")
            ->first();      

        if(!is_object($queryAgent))
            return [];

        $array = $this->parseHierarchyToArrayFormat($queryAgent->hierarchy);
        return $array;

    }


    public function getAgentByMemberID($member_id)
    {     
        $query = DB::table("agents")->whereIn('id', function($q) use ($member_id)
        {
           $q->from('members')
            ->where('id', $member_id)
            ->select("parent");
        })
        ->first();        

        return $query;

    }

     
    public function parseHierarchyToArrayFormat($agentHierarchy)
    { 
        $agentHierarchy = str_replace("[","", $agentHierarchy);
        $agentHierarchy = str_replace("]","", $agentHierarchy);
        $array = explode(",", $agentHierarchy);
        $array = array_diff($array, [ "-1" , "-2"]);                
        return $array;
    }

}