<?php

namespace App\Repositories;

use DB;
use App\Enums\AgentPersonalSettingKeyEnum;

class AgentPersonalSettingRepository
{   

    /* 取得設置為系統維護中的代理IDs */
    public function getMaintainAgents($target_agentIDs = [])
    {     
        $setting_id_flag = AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG;
        $setting_id_start_time = AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME;
        $setting_id_end_time = AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME;

        $utcNow = gmdate("Y-m-d H:i:s");

        $querySettings = DB::table("agent_personal_settings as a")

            ->leftJoin("agent_personal_settings as as" , function ($join) use ($setting_id_start_time) {
                $join->on('a.agent_id', 'as.agent_id')->where('as.setting_id', $setting_id_start_time );
            })
            ->leftJoin("agent_personal_settings as ae" , function ($join) use ($setting_id_end_time) {
                $join->on('a.agent_id', 'ae.agent_id')->where('ae.setting_id', $setting_id_end_time );
            })
            ->where('a.value', "1")
            ->where('a.setting_id', $setting_id_flag)
            ->where('as.value', "<" , $utcNow)
            ->where('ae.value', ">" , $utcNow);


        //如果有指定agent_IDs 額外篩選
        if(count($target_agentIDs) > 0)
        {
            $querySettings = $querySettings->whereIn("a.agent_id" ,$target_agentIDs);
        }

        $querySettings = $querySettings
            ->select("a.agent_id" , "as.value as start_time" , "ae.value as end_time")
            ->get();            

        return $querySettings;
    }
        

    private function getSettingByKeyAndAgentID($querySetting)
    {

    }

    
    public function getFirstSettingByMemberId($memberID , $settingId)
    {     
        $queryHierarchyPath = DB::table("members as m")
            ->leftJoin("agents as a" , "m.parent" , "a.id")
            ->where("m.id" , $memberID)
            ->select("a.hierarchy_path")
            ->first();
        
        if(!is_object($queryHierarchyPath)) {
            throw new Exception("not found.", 4044);
        } 

        $agentIDs = explode("/" , $queryHierarchyPath->hierarchy_path);
        $agentIDs = array_diff($agentIDs, [""]);

        $query = $this->getSettingByAgentIDs($agentIDs , [ $settingId ]);

        $count = count($query);

        if($count <= 0)
        {
            return null;
        }

        return $query->first();
    }

    
    public function getSettingByAgentIDs($agentIds , $settingIds)
    {     
        $querySettings = DB::table("agent_personal_settings")
            ->whereIn('agent_id',  $agentIds)
            ->whereIn('setting_id',  $settingIds)
            ->orderBy("agent_id" , 'desc')
            ->get();                    

        return $querySettings;
    }
}