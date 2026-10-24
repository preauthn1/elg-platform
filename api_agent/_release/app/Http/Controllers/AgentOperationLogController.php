<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\AgentPermission;
use App\Models\AgentMenu;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Helpers\Common;
use App\Helpers\ArrayHelper;
use App\Models\AgentOperateLogs;
use App\Models\AgentPersonalSettings;
use App\Enums\AgentSystemConstant;
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\LanguageTextsRepository;
use App\Repositories\AgentRepository;
use App\Repositories\AgentOperationLogRepository;
use App\Repositories\AgentSettingDefineRepository;





class AgentOperationLogController extends Controller
{


    /**
    * agent/operate/logs
    * -取得代理操作紀錄
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function GetAgentOperationLogSettings(Request $request)
    {
        try {                   

            $currentAgendID = $request->HOST;

            $agentRep = new AgentRepository();

            $superAdminCheck = $agentRep ->getOrganizationID_Or_IsSuperAdminOrSubAccount($currentAgendID);

            $queryMy = $agentRep->getById($currentAgendID);


            //** 這邊有指定的types 才會在後台操作允許查出來
            //** 這邊有指定的types 才會在後台操作允許查出來
            //** 這邊有指定的types 才會在後台操作允許查出來
            //** 這邊有指定的types 才會在後台操作允許查出來
            $availableTypes = $this->GetAvailableTypes($request->HOST);

            if(!isset($request->start_time))
            {
                $request->start_time =  date('Y-m-d 00:00', strtotime('+8hour'));                
            }

            if(!isset($request->end_time))
            {
                $request->end_time =  date('Y-m-d 23:59', strtotime('+8hour'));                
            }

            $start_time =  date('Y-m-d H:i:s', strtotime($request->start_time.'-8hour'));
            $end_time =  date('Y-m-d H:i:s', strtotime($request->end_time.'-8hour'));
           
            $query = DB::table("agent_operate_logs as log")
                ->leftJoin("agents as a" , "log.agent_id" , "a.id")
                ->leftJoin("agents as target" , "log.reference_id" , "target.id")
                ->leftJoin("members as targetMember" , "log.reference_id" , "targetMember.id")
                ->whereIn("log.type" ,  $availableTypes)
                ->where("log.created_at" , ">" , $start_time)
                ->where("log.created_at" , "<" , $end_time);

            //目前只開放查整個組織(vworld/MMGames)
            if(!$superAdminCheck["is_super_admin"])
            {
                $targetHierarchyPath = $queryMy->hierarchy_path;
                $query = $query->where("a.organization_id" , $superAdminCheck["organization_id"]);
                $query = $query->where("a.hierarchy_path" , "like" ,  $targetHierarchyPath."%");         
            }

            if(!Common::IsNullOrEmptyString($request->operator_username))
            {
                $query = $query->where("a.username" , $request->operator_username);
            }

            if(!Common::IsNullOrEmptyString($request->target_username))
            {
                $query = $query
                    ->where("target.username" , $request->target_username)
                    ->orWhere("targetMember.username" , $request->target_username);
            }

            if(!Common::IsNullOrEmptyString($request->type))
            {
                $query = $query->where("log.type" , $request->type);
            }

            $total = $query->count();            

            $result = $query
                ->select("log.*" , "a.username as operator" , "target.username as targetAgent" , "targetMember.username as targetMember" , "a.organization_id")
                ->orderBy('log.id', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            foreach ($result as $key => $item) {              
                $item->type_name = __('param.agent_operate_type_action.'.$item->type);
                $item->ip = Common::ParseIPAddressAndFilterGooglePlatformIPAddress($item->ip);
                $item->targetUserName = $item->targetAgent;

                if(Common::IsNullOrEmptyString($item->targetAgent) && 
                   Common::IsNotNullOrEmptyString($item->targetMember))
                {
                    $item->targetUserName = $item->targetMember;
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);


        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }


    /**
    * agent/operate-log-types/dropdownList
    * -取得代理操作紀錄
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function GetOperateTypeDropdown(Request $request)
    {
        try {                
            //** 這邊有指定的types 才會在後台操作允許查出來
            $availableTypes = $this->GetAvailableTypes($request->HOST);

            $resultArray = array();

            foreach($availableTypes as $eachType)
            {
                $instance = array();
                $instance["value"] = $eachType;
                $instance["text"] =  __('param.agent_operate_type_action.'.$eachType);

                array_push(   $resultArray ,   $instance );
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $resultArray
            ]);


        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }



    private function GetAvailableTypes($agentID_or_HostID)
    {  
        //** 這邊有指定的types 才會在後台操作允許查出來
        $availableTypes = AgentSystemConstant::availableOperateTypes();

        $rep = new AgentRepository();
        $isSuperAdmin = $rep->isSuperAdminOrSubAccount($agentID_or_HostID);

        //非超級管理員的話，會隱藏某些項目
        if(!$isSuperAdmin)
        {
            $availableTypes = array_diff($availableTypes , ["migrate_agent"]);
            $availableTypes = array_diff($availableTypes , ["agent_permission_sync"]);
        }

        return $availableTypes;
    }

}