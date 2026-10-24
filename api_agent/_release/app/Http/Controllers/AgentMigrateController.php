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
use App\Models\AgentOperateLogs;
use App\REDIS;
use Hash;
use Validator;
use App\Helpers\ArrayHelper;
use DB;
use App\Repositories\AgentRepository;
use App\Repositories\AgentOperationLogRepository;

class AgentMigrateController extends Controller
{

    /**
     * agent/hierarchy-migrate/preview
     * 取得代理移轉的預覽資訊
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          
     *        }
     *    ],
     * }
     */
    public function GetAgentHierarchyMigrateResultPreview(Request $request)
    {
        try {         

            $target_agent_id = $request->target_agent_id;
            $new_parent_agent_id =  $request->new_parent_agent_id;

            $preview = $this->getMigrateResultPreview( $target_agent_id ,$new_parent_agent_id);         

            if($preview["error_msg"] != null && $preview["error_msg"] != '')
            {
                return response()->json([
                    'code' => 9999,
                    'message' => __('error_message.9999'),                    
                    'data' => $preview["error_msg"]
                ]);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $preview
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
     * agent/hierarchy-migrate
     * 執行代理移轉的SQL指令
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          
     *        }
     *    ],
     * }
     */
    public function AgentHierarchyMigrateExecute(Request $request)
    {
        try {         
            $target_agent_id = $request->target_agent_id;
            $new_parent_agent_id =  $request->new_parent_agent_id;

            $preview = $this->getMigrateResultPreview( $target_agent_id ,$new_parent_agent_id );
         
            if(isset($preview->error_msg) && $preview->error_msg != '')
            {
                return response()->json([
                    'code' => 9999,
                    'message' => __('error_message.9999'),                    
                    'data' => $preview->error_msg
                ]);
            }

            $repOfLog = new AgentOperationLogRepository();
            $jsonDebug = json_encode( $preview);

            //step 1. 寫入異動紀錄
            $repOfLog->insert( $request->ID , $target_agent_id , 'migrate_agent' , $jsonDebug);

            //step 2. 執行update語法
            DB::statement($preview["sqlRaw_1"]);
            DB::statement($preview["sqlRaw_2"]);    
            DB::statement($preview["sqlRaw_3"]);     
            

            $redis = new REDIS();
            $redis_key = array('recently_query_all_agent');

            $unexpected_agentIDs = array();
                        
            //執行更新後，檢查是否樹狀結構有異常
            if ($redis->exists($redis_key)) 
            {
                //如果近期N分鐘內 有執行過檢查，則下一次僅針對這次相關的agentIDs做檢查
                $unexpected_agentIDs = $this->checkAgentHierarchyValueUnexpected($preview["update_agentIDs"]);
            }
            else
            {                
                $unexpected_agentIDs = $this->checkAgentHierarchyValueUnexpected(null);
                $redis->setWithExpiredTime($redis_key , "yes" , 180);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 
                [
                    "unexpected_agentIDs" => $unexpected_agentIDs,
                    "unexpected_agentIDsText" => implode(',' , $unexpected_agentIDs)
                ]
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
     * agent/hierarchy-check
     * 檢查是否有代理的 hierarchy異常
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          
     *        }
     *    ],
     * }
     */
    public function checkAnyAgentHierarchyFatalError(Request $request)
    {
        try {                    
            $errorAgentIDs = $this->checkAgentHierarchyValueUnexpected(null);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' =>
                [
                    'unexpected_agentIDs' =>  $errorAgentIDs,
                    'unexpected_agentIDsText' => implode(',' , $errorAgentIDs)
                ]
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


    /* 檢查是否有代理的 hierarchy 跟 hierarchy_path 對不起來 */
    private function checkAgentHierarchyValueUnexpected($filterAgentIDs)
    {
        $agentRep = new AgentRepository();

        $queryExpression =  DB::table("agents as a");

        if(!is_null($filterAgentIDs) && count($filterAgentIDs) > 0)
        {
            $queryExpression = $queryExpression->whereIn("id" , $filterAgentIDs);
        }

        $queryAllAgents = $queryExpression
            ->select("id" , 'parent', "hierarchy" , 'hierarchy_path')
            ->get();

        $errorAgentIDs = array();

        foreach ($queryAllAgents as $item) 
        {
            $arrays_1 = $agentRep->parseHierarchyToArrayFormat($item->hierarchy);
            $arrays_1 = array_values(array_diff($arrays_1 , [ "0" ]));            
            $arrays_1 = array_reverse($arrays_1);

            $arrays_2  = explode('/' , $item->hierarchy_path);
            $arrays_2 = array_values(array_diff($arrays_2 , [ "" ]));

            $debug = "";

            if($item->id <= 1)
            {
                continue;
            }

            //錯誤1. 筆數對不起來
            if(count($arrays_1) != count($arrays_2))
            {
                array_push($errorAgentIDs  , $item->id);
            }
            else if(!in_array($item->id , $arrays_2))
            {
                //錯誤2-1. 自己id不存在於hierarchy
                array_push($errorAgentIDs  , $item->id);
            }
            else if(!in_array($item->parent , $arrays_2))
            {
                //錯誤2-2. parent不存在於hierarchy
                array_push($errorAgentIDs  , $item->id);
            }
            else if($arrays_2[count( $arrays_2) - 1] !=  $item->id)
            {
                //錯誤2-3. 自己的id不在最後一筆
                array_push($errorAgentIDs  , $item->id);
            }
            else
            {
                //錯誤3. hierarchy 跟 hierarchy_path 順序對不起來
                for ($i = 0; $i < count($arrays_1); $i++) {

                    if($arrays_1[$i] !=  $arrays_2[$i])
                    {
                        array_push($errorAgentIDs  , $item->id);
                        break;
                    }
                }
            }      
        }

        return $errorAgentIDs;
    }



    private function getMigrateResultPreview($target_agent_id , $new_parent_agent_id)
    {
        // $target_agent_id = 4276;
        // $new_parent_agent_id = 4327;

        $rep = new AgentRepository();   

        $query_target_agents = $rep->getById($target_agent_id);
        $query_new_parent_agents = $rep->getById($new_parent_agent_id);
        $query_original_parent_agents = $rep->getById($query_target_agents->parent);


        $isSelf = $target_agent_id == $new_parent_agent_id ;
        $isAnyAgentNull = !is_object($query_target_agents) || !is_object($query_new_parent_agents) || !is_object($query_original_parent_agents);
        $isNotSameOrganization = $query_target_agents->organization_id != $query_new_parent_agents->organization_id;
        $isSameParentError = $query_target_agents->parent == $new_parent_agent_id;
        $isTargetAgentAlreadyParentOfNewAgent = $query_new_parent_agents->parent == $target_agent_id;


        //防呆錯誤檢查
        if( $isAnyAgentNull ||
            $isNotSameOrganization  ||
            $isTargetAgentAlreadyParentOfNewAgent ||
            $isSelf ||
            $isSameParentError)
        {
            return [
                'error_msg' => ' $isAnyAgentNull：'. ($isAnyAgentNull ? "1" : "0").
                '、isSameParentError：'.($isSameParentError ? "1" : "0").
                '、isNotSameOrganization：'.($isNotSameOrganization ? "1" : "0").
                '、isTargetAgentAlreadyParentOfNewAgent:'.$isTargetAgentAlreadyParentOfNewAgent.
                '、isSelf:'.($isSelf ? "1" : "0"),
            ];
        }
                    
        $queryAllMyHierarchyChilds = $rep->getAllHierarchyChilds($target_agent_id);

        //舊版欄位 (hierarchy)
        $originalHierarchyAgentIDs =  $rep->parseHierarchyToArrayFormat($query_target_agents->hierarchy);
        $newParentHierarchyAgentIDs =  $rep->parseHierarchyToArrayFormat($query_new_parent_agents->hierarchy);

        //新版欄位 (hierarchy_path)
        $replace_from_hierarchy_path = $query_original_parent_agents->hierarchy_path;
        $replace_to_hierarchy_path =  $query_new_parent_agents->hierarchy_path;        
                    
        $hierarchyChilds = array();      

        //要取代得字串
        $originalParentHierarchyAgentIDs = array_diff($originalHierarchyAgentIDs, [ $target_agent_id ]);       

        $replace_from = ",".implode (",", $originalParentHierarchyAgentIDs).",";
        $replace_to = ",".implode (",", $newParentHierarchyAgentIDs).",";
        

        $sqlRaw_1 = "UPDATE agents SET parent = ".$new_parent_agent_id.", cs_sync_flag = 1 WHERE id = ".$target_agent_id;
        $sqlRaw_2 = "UPDATE agents SET hierarchy = REPLACE(hierarchy, '". $replace_from."' ,  '". $replace_to."') WHERE hierarchy like  '%,".$target_agent_id.",%'";
        $sqlRaw_3 = "UPDATE agents SET hierarchy_path = REPLACE(hierarchy_path, '". $replace_from_hierarchy_path."' ,  '". $replace_to_hierarchy_path."') WHERE hierarchy_path like  '".$query_target_agents->hierarchy_path."%'";                    
        $sqlRaw_4 = "SELECT * FROM agents WHERE hierarchy like '%,".$target_agent_id.",%'; ";

        foreach ($queryAllMyHierarchyChilds as $key => $item) 
        {               
            if( $item->id != $target_agent_id)
            {
                array_push( $hierarchyChilds , 
                [
                    "agent_id" => $item->id,
                    "username" => $item->username,
                    "hierarchy" => $item->hierarchy,
                    "hierarchy_path" => $item->hierarchy_path,
                    "expect_new_path" => str_replace( $replace_from_hierarchy_path  ,$replace_to_hierarchy_path , $item->hierarchy_path)
                ]);          
            }
        }


        return [
                'error_msg' => null,
                "target_agent" => [

                    "agent_id" => $query_target_agents->id,
                    "username" => $query_target_agents->username,
                    "parent" => $query_target_agents->parent,
                    "hierarchy" => $query_target_agents->hierarchy,
                    "hierarchy_path" => $query_target_agents->hierarchy_path,
                ],

                "original_agent" => [

                    "agent_id" => $query_original_parent_agents->id,
                    "username" => $query_original_parent_agents->username,
                    "parent" => $query_original_parent_agents->parent,
                    "hierarchy" => $query_original_parent_agents->hierarchy,
                    "hierarchy_path" => $query_original_parent_agents->hierarchy_path,
                ],

                "new_parent_agent" => [

                    "agent_id" => $query_new_parent_agents->id,
                    "username" => $query_new_parent_agents->username,
                    "parent" => $query_new_parent_agents->parent,
                    "hierarchy" => $query_new_parent_agents->hierarchy,
                    "hierarchy_path" => $query_new_parent_agents->hierarchy_path,
                ],

                "update_agentIDs" => ArrayHelper::StdClass_ToSingleColumnArray($queryAllMyHierarchyChilds , "id"),
                "hierachyChilds" => $hierarchyChilds,
                "sqlRaw_1" => $sqlRaw_1,
                "sqlRaw_2" => $sqlRaw_2,
                'sqlRaw_3' => $sqlRaw_3 
            ];
    }



    private function messages()
    {
        return [
            'id.required' => __('error_message.2017'),
            'id.digits_between' => __('error_message.2018'),

            'username.required' => __('error_message.1026'),
            'username.alpha_num' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'nickname.between' => __('error_message.2024'),

            'email.required' => __('error_message.1013'),
            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'confirm_password.different' => __('error_message.1021'),

            'mobile.digits_between' => __('error_message.1036'),

            'timezone_id.digits_between' => __('error_message.1054'),

            'country_id.digits_between' => __('error_message.2022'),

            'zip_code.digits_between' => __('error_message.1038'),

            'district.between' => __('error_message.1039'),

            'city.between' => __('error_message.1040'),

            'address.between' => __('error_message.1041'),

            'email_validate.boolean' => __('error_message.1091'),
            'mobile_validate.boolean' => __('error_message.1092'),
            'frontend_register.boolean' => __('error_message.1065'),

            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}