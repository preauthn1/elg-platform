<?php

namespace App\Repositories;

use App\Models\AgentSettingDefine;


class AgentSettingDefineRepository extends Repository
{   
    public function __construct()
    {
        $this->model = new AgentSettingDefine();
    }

    public function GetBySettingIDs($setting_ids_array)
    { 
        $query = $this->model::whereIn("setting_id" , $setting_ids_array)
            ->get();

        return $query;
    }

}