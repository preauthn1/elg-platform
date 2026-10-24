<?php

namespace App\Repositories;

use App\Models\Agent;
use DB;

class AgentRepository extends Repository
{
    /**
     * AgentRepository constructor.
     * @param Agent $model
     */
    public function __construct()
    {
        $this->model = new Agent();
    }

    /**
     * 新增
     * @param array $request
     * @return boolean
     */
    public function insert($request)
    {
        foreach ($request->input() as $k => $v) {
            $this->model->$k = $v;
        }

        return $this->model->save();
    }

    /**
     * 查詢
     * @return Object
     */
    public function collect()
    {
        return $this->all();
    }

    /**
     * 編輯
     * @param array $request
     * @param int $key_id
     * @return boolean
     */
    public function updateBy($request, $key_id)
    {
        $data = $this->collectBy($key_id);
        if (!$data) {
            return false;
        }

        foreach ($request->input() as $k => $v) {
            $data->$k = $v;
        }

        return $data->save();
    }

    /**
     * 編輯狀態
     * @param array $request
     * @param int $key_id
     * @return boolean
     */
    public function updateForStatus($request, $key_id)
    {
        $data = $this->collectBy($key_id);
        if (!$data) {
            return false;
        }

        $data->status = $request['status'];

        return $data->save();
    }


    public  function getById($agent_id)
    { 
        $agent = $this->model
            ->where('id', $agent_id)
            ->first();

        return $agent;
    }

    public function getAllHierarchyChilds($agent_id)
    { 
        $agents = $this->model
            ->where('hierarchy' , 'like' , "%".$agent_id."%")
            ->get();

        return $agents ;
    }


    public function getHierarchyAgentIDsByAgentID($agent_id)
    { 
        $agent = $this->model
            ->where('id', $agent_id)
            ->first();

        if(!is_object($agent))
            return [];
   
        return $this->parseHierarchyToArrayFormat($agent->hierarchy);
    }

    
    public function parseHierarchyToArrayFormat($agentHierarchy)
    { 
        $agentHierarchy = str_replace("[","", $agentHierarchy);
        $agentHierarchy = str_replace("]","", $agentHierarchy);
        $array = explode(",", $agentHierarchy);
        $array = array_diff($array, [ "-1" , "-2"]);                
        return $array;
    }


    public function parseToHierarchyPathFormat($agentHierarchy)
    { 
        $array = $this->parseHierarchyToArrayFormat($agentHierarchy);

        //移除0
        $array = array_diff($array, [ 0 ]);       

        $array_reverse = array_reverse($array);           

        $resultPath = "";

        foreach ($array_reverse as $value) {
            $resultPath = $resultPath."/".$value;
        }
          
        return $resultPath;
    }



    public function isViewPermission($currentOperateAgentID, $targetAgentID)
    {
        if($currentOperateAgentID == $targetAgentID)
            return true;

        return $this->isChild($currentOperateAgentID , $targetAgentID);
    }    


    public function isChild($parentId, $childId)
    {
        //查詢下線帳號的上線有沒有父帳號
        $agent = $this->model
            ->where('id', $childId)
            ->where('hierarchy', 'like', '%,' . $parentId . ',%')
            ->first();

        if (is_object($agent)) {
            return true;
        } else {
            return false;
        }
    }    

    public function isSuperAdminOrSubAccount($agent_id)
    {
        $superAdminCheck = $this->getOrganizationID_Or_IsSuperAdminOrSubAccount($agent_id);
        return $superAdminCheck["is_super_admin"];
    }


    public function getOrganizationID_Or_IsSuperAdminOrSubAccount($agent_id)
    {
        //如果是superAdmin的話 某些功能可以查到額外的東西
        $super_admin_agent_id = 1;

        if($agent_id ==  $super_admin_agent_id)
        {
            return 
            [
                "is_super_admin" => true,
                "organization_id" => 1, 
            ];
        }   

        $agent = $this->model
            ->where('id', $agent_id)
            ->first();

        //若是admin的子帳號 host_id 會是admin
        return 
        [
            "is_super_admin" => $agent->host_id == $super_admin_agent_id,
            "organization_id" =>  $agent->organization_id, 
        ];
    }

    public function getAgentGroup($host_id, $group_id) {
        $agentRep = new AgentRepository();
        $superAdminCheck = $agentRep ->getOrganizationID_Or_IsSuperAdminOrSubAccount($host_id);
        $query = DB::table("agent_groups as g")
            ->leftJoin("organizations as o" , "g.organization_id" , "o.id");
        if(!$superAdminCheck["is_super_admin"])
        {
            $query = $query->where("g.organization_id" , $superAdminCheck["organization_id"]);
        }
        $result = $query
            ->where("g.id", $group_id)
            ->select("g.*" , "o.name as organization_name")->first();
        return $result;
    }
}