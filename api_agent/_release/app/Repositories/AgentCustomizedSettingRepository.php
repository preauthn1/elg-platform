<?php

namespace App\Repositories;

use App\Models\AgentSettingDefine;
use DB;


class AgentCustomizedSettingRepository extends Repository
{   
    public function __construct()
    {
        $this->model = new AgentSettingDefine();
    }


    public function getSettingByGroupID($agent_group_id , $setting_id)
    { 
        $query = $this->getSettingsByGroupID($agent_group_id , [$setting_id]);
        return $query->first();
    }

    public function getSettingsByGroupID($agent_group_id , $setting_ids_array)
    { 
        //若該組別沒有設置 setting_value會使用 [預設值]
        $query = DB::table("agent_setting_defines as def")
            ->leftJoin("agent_customized_settings as agc" , function ($join) use ($agent_group_id) {
                $join->on('def.setting_id', 'agc.setting_key')->where('agc.agent_group_id', $agent_group_id);
            })
            ->whereIn("def.setting_id" , $setting_ids_array)
            ->select(
                "def.setting_id", 
                "def.memo" , 
                "def.default_value" , 
                "agc.value as my_setting_value" , 
                DB::raw("IFNULL(agc.value , def.default_value ) as setting_value")
            )
            ->get();

        return $query;
    }

}