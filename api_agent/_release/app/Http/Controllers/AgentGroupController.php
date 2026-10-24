<?php

namespace App\Http\Controllers;

use App\Models\VendorCustomizedItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\AgentPermission;
use App\Models\AgentMenu;
use App\Models\AgentVendor;
use App\Models\AgentCustomizedSetting;
use App\Models\Vendor;
use App\Helpers\Common;
use App\Helpers\ArrayHelper;
use App\Models\AgentGroup;
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\AgentRepository;
use App\Models\AgentGroupPermission;
use App\Services\CSDataSyncService;

class AgentGroupController extends Controller
{
    /**
     * agent/group/list
     * - 取得代理group列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function list(Request $request)
    {
        try {
            $agentOrHostID = $request->HOST;

            $agentRep = new AgentRepository();

            $superAdminCheck = $agentRep ->getOrganizationID_Or_IsSuperAdminOrSubAccount($agentOrHostID);

            $query = DB::table('agent_groups as g')
                ->leftJoin('organizations as o', 'g.organization_id', 'o.id');
            if ($request->org_id > 0) {
                $query = $query->where('o.id', $request->org_id);
            }
            $eachGroupCountValue = $this->getAgentGroupCountByCache();
            
            //目前只開放查整個組織(vworld/MMGames)
            if(!$superAdminCheck["is_super_admin"])
            {
                $query = $query->where("g.organization_id", $superAdminCheck["organization_id"]);

                $agent_group = $this->getAgentGroupArray($request->ID);
                $query = $query->whereIn('g.id', $agent_group);
            }

            if(isset($request->agent_group_type) && $request->agent_group_type != "" && $request->agent_group_type != " ")
            {
                $query = $query->where("g.agent_group_type", $request->agent_group_type);
            }
            
            $total = $query->count();        
                
            $result = $query->orderBy("g.agent_group_type" , "ASC")
                ->orderBy('g.id', 'DESC')
                ->select(
                    'g.*',
                    'o.name as organization_name'
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            foreach ($result as $key => $item) {
                $count = 0;
                switch ($item->agent_group_type) {
                    case 1:
                        $count =  $eachGroupCountValue["customized_setting_group"][$item->id] ?? 0;
                        break;
                    case 2:
                        $count =  $eachGroupCountValue["cash_flow_group"][$item->id] ?? 0;
                        break;
                    case 3:
                        $count =  $eachGroupCountValue["sms_gateway_group"][$item->id] ?? 0;
                        break;
                    case 4:
                        $count =  $eachGroupCountValue["chat_group"][$item->id] ?? 0;
                        break;
                    case 5:
                        $count =  $eachGroupCountValue["vendor_customize_group_id"][$item->id] ?? 0;
                        break;
                }

                $item->total_agent_count = $count;
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
     * agent/group/dropdownList
     * - 取得代理group列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function dropdownList(Request $request)
    {
        try {
            $agentOrHostID = $request->HOST;

            $agentRep = new AgentRepository();

            $superAdminCheck = $agentRep ->getOrganizationID_Or_IsSuperAdminOrSubAccount($agentOrHostID);


            $query = DB::table("agent_groups as g")
                ->leftJoin("organizations as o" , "g.organization_id" , "o.id");    
        
            //目前只開放查整個組織(vworld/MMGames)
            if(!$superAdminCheck["is_super_admin"])
            {
                $query = $query->where("g.organization_id" , $superAdminCheck["organization_id"]);
            }       

            if(Common::IsNotNullOrEmptyString($request->organization_id))
            {
                $query = $query->where("g.organization_id" , $request->organization_id);
            }  

            $result = $query
                ->select("g.id" , "g.name" , "g.agent_group_type")
                ->orderBy("g.id" , "ASC")
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result
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
     * agent/group/create
     * - 新增代理group列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function create(Request $request)
    {
        try {

            $model = new AgentGroup();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }


            $insert = array();
            $insert["name"] = $request->name;
            $insert["memo"] = $request->memo;
            $insert["organization_id"] = $request->organization_id;
            $insert["agent_group_type"] = $request->agent_group_type;
            $insert["is_default"] = 0;


            $result = AgentGroup::create($insert);

            //如果是個性化組別設置 自動塞預設資料
            if($request->agent_group_type == 1)
            {
                $this->addDefaultCustomizedSettingData($result->id);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => "",
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
     * agent/group/update
     * - 更新代理group
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function update(Request $request)
    {
        try {

            $model = new AgentGroup();
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $data = array();
            $data["name"] = $request->name;
            $data["memo"] = $request->memo;

            $queryExist = AgentGroup::where("id" , $request->id)->first();

            
            if(!is_object($queryExist))
            {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $queryExist->update($data);
        

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => "",
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
     * agent/group/create-by-copy
     * - 以某個組別當基準 複製一個一樣的設置出來
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function createFromCopy(Request $request)
    {
        try {

            $model = new AgentGroup();

            $validator = Validator::make($request->all(), $model->rules_copy, $this->messages());        
            
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $copyFrom = $model->where("id" , $request->target_group_id)->first();

            $insert = array();            
            $insert["name"] = "(Copy)".$copyFrom->name;
            $insert["memo"] = 'copy';
            $insert["organization_id"] = $copyFrom->organization_id;
            $insert["agent_group_type"] = $copyFrom->agent_group_type;
            $insert["is_default"] = 0;

            $result = AgentGroup::create($insert);

            //如果是個性化組別設置 複製設置
            if($copyFrom->agent_group_type == 1)
            {                
                $querySettings =  DB::table("agent_customized_settings as setting")
                    ->where("agent_group_id" , $copyFrom->id)
                    ->select("setting.*")
                    ->get();    
        
                $insertRows = array(); 

                foreach ($querySettings as $item) {           
        
                    $insert = json_decode(json_encode($item) , true);
                    $insert["agent_group_id"] = $result->id;

                    unset($insert["id"]);
                    unset($insert["created_at"]);
                    unset($insert["updated_at"]);

                    array_push($insertRows , $insert);
                }                
            
                AgentCustomizedSetting::insert($insertRows);                
            }
            //如果是遊戲商入口組別設置 複製設置
            if($copyFrom->agent_group_type == 5)
            {
                $querySettings =  DB::table("vendor_customized_items")
                    ->where("agent_group_id" , $copyFrom->id)
                    ->select("*")
                    ->get();

                $insertRows = array();

                foreach ($querySettings as $item) {
                    $insert = json_decode(json_encode($item) , true);
                    $insert["agent_group_id"] = $result->id;

                    unset($insert["id"]);
                    unset($insert["created_at"]);
                    unset($insert["updated_at"]);

                    array_push($insertRows , $insert);
                }

                VendorCustomizedItem::insert($insertRows);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => "",
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


    public function getAgentGroupPermissions(Request $request)
    {
        try {
            $agentOrHostID = $request->HOST;

            $agentRep = new AgentRepository();

            $agent = Agent::find($request->agent_id);
            $has_permissions = $this->getAgentGroupArray($agent->id);

            $superAdminCheck = $agentRep->getOrganizationID_Or_IsSuperAdminOrSubAccount($agentOrHostID);

            $query = DB::table('agent_groups as g')
                ->leftJoin('organizations as o', 'g.organization_id', 'o.id');

            //目前只開放查整個組織(vworld/MMGames)
            if (!$superAdminCheck["is_super_admin"]) {
                $query = $query->where("g.organization_id", $superAdminCheck["organization_id"]);

                $agent_group = $this->getAgentGroupArray($request->ID);
                $query = $query->whereIn('g.id', $agent_group);
            }

            $result = $query->where('g.organization_id', $agent->organization_id)
                ->orderBy("g.agent_group_type", "ASC")
                ->orderBy('g.id', 'ASC')
                ->select(
                    'g.id as group_id',
                    'g.agent_group_type',
                    'g.name as group_name',
                    'g.agent_group_type as group_type'
                )
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    "agent_id" => $agent->id,
                    "agent_name" => $agent->username,
                    "has_permissions" => $has_permissions,
                    "groups" => $result
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

    public function updateAgentGroupPermissions(Request $request)
    {
        try {
            $model = new AgentGroupPermission();

            $group_permission = $request->group_permission;
            //先把有開啟的全部關掉,再做新增或修改
            AgentGroupPermission::where('agent_id', $request->agent_id)->update(['status' => 0]);
            foreach ($group_permission as $group_id) {
                $permission = $model->where('agent_id', $request->agent_id)->where('agent_group_id', $group_id)->first();
                if (is_object($permission)) {
                    $permission->update(['status' => 1]);
                } else {
                    $data = array(
                        'agent_id' => $request->agent_id,
                        'agent_group_id' => $group_id,
                        'status' => 1,
                        'admin' => $request->ID
                    );
                    AgentGroupPermission::create($data);
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => ''
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

    private function addDefaultCustomizedSettingData($tragetGroupID)
    {       
        $querySettings =  DB::table("agent_setting_defines as def")
            ->where("def.type" , 3)
            ->select("def.setting_id" , "def.data_type" , "def.default_value")
            ->get();


        $insertRows = array();

        
        foreach ($querySettings as $item) {           

            $insert = array();
            $insert["agent_group_id"] = $tragetGroupID;
            $insert["sub_category"] = '';  
            $insert["setting_key"] = $item->setting_id;
            $insert["memo"] = '';
            $insert["value"] = '';

            if($item->data_type == "language_text")
            {
                $insert["sub_category"] = '1'; //預設會塞英文資料
            }

            if(!is_null($item->default_value))
            {
                $insert["value"] = $item->default_value;   
            }
    
            array_push($insertRows , $insert);
        }                
    
        AgentCustomizedSetting::insert($insertRows);

    }



    private function getAgentGroupCountByCache()
    {
        $redis = new REDIS();
        $redisKey = array("AgentGroupCount");

        if ($redis->exists($redisKey)) 
        {
            $result = $redis->get($redisKey);
            return $result;
        }

        $queryAgents = DB::table("agents")
            ->select(
                "customized_setting_group_id" ,
                "cash_flow_group_id" ,
                "sms_gateway_group_id" ,
                "chat_group_id",
                "vendor_customize_group_id"
            )
            ->get();
        $result =  
        [
            "customized_setting_group" => array_count_values(ArrayHelper::StdClass_ToSingleColumnArray($queryAgents , "customized_setting_group_id")),
            "cash_flow_group" => array_count_values(ArrayHelper::StdClass_ToSingleColumnArray($queryAgents , "cash_flow_group_id")),
            "sms_gateway_group" => array_count_values(ArrayHelper::StdClass_ToSingleColumnArray($queryAgents , "sms_gateway_group_id")),
            "chat_group" => array_count_values(ArrayHelper::StdClass_ToSingleColumnArray($queryAgents , "chat_group_id")),
            "vendor_customize_group_id" => array_count_values(ArrayHelper::StdClass_ToSingleColumnArray($queryAgents , "vendor_customize_group_id"))
        ];

        //三分鐘cache
        $redis->setWithExpiredTime($redisKey ,  $result, 180);
        return $result;    
    }

    private function getAgentGroupArray($agent_id)
    {
        $result = DB::table('agent_group_permissions')
            ->where('agent_id', $agent_id)
            ->where('status', 1)
            ->pluck('agent_group_id')
            ->toArray();

        return $result;
    }


    private function messages()
    {
        return [           
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }

}