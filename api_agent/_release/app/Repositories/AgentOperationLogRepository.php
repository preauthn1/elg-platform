<?php

namespace App\Repositories;

use App\Models\AgentOperateLogs;
use App\Helpers\Common;

class AgentOperationLogRepository extends Repository
{
    /**
     * AgentRepository constructor.
     * @param Agent $model
     */
    public function __construct()
    {
        $this->model = new AgentOperateLogs();
    }

    public function insert($agent_id , $reference_id , $type , $debug_info = "")
    {   
        $myIP = Common::getIp();

        $insert_log = array();
        $insert_log["agent_id"] = $agent_id;
        $insert_log["type"] = $type;
        $insert_log["reference_id"] = $reference_id;
        $insert_log["ip"] = $myIP;
        $insert_log["debug_info"] = $debug_info;

        return $this->model->create($insert_log );
    }

}