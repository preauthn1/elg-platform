<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use App\Models\AgentPermission;
use App\Models\Agent;
use App\Helpers\ArrayHelper;
use App\Repositories\AgentOperationLogRepository;
use Validator;
use DB;
use App\REDIS;

class AgentPermissionController extends Controller
{

    /**
     * agentPermission/setting
     * -設定營運商權限
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @bodyParam menus array required 選單{"選單編號":"操作功能"} Example: {"1":"view,update,delete","2":"view","3":"view"}
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function setting(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new AgentPermission();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 刪除舊有的權限
            $model->where('agent_id', $request->agent_id)->delete();
            // 新增權限
            $data = array();
            $data['agent_id'] = $request->agent_id;
            $data['admin'] = $request->ID;
            foreach ($request->menus as $key => $action) {
                // 如果選單編號不是數字直接報錯
                if (! is_numeric($key)) {
                    return response()->json([
                        'code' => 1060,
                        'message' => __('error_message.1060')
                    ]);
                }
                $data['agent_menu_id'] = $key;
                $data['action'] = $action;
                $result = $model->create($data);
            }

            DB::commit();
            if ($result) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'agent_menus',
                    $request->agent_id
                ));
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } catch (QueryException $e) {
            DB::rollback();
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
     * agentPermission/check
     * - 檢查權限是否存在
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @bodyParam menu_code string required 權限代碼 Example: create_agent
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     *  "data" : {     
     *      "is_exist" : false
     *    }
     * }
     */
    public function check(Request $request)
    {
        try {
            
            $var_agent_id = $request->agent_id;
            $var_menu_code = $request->menu_code;

            $result = DB::select(
                DB::raw("                
                    SELECT * FROM agent_permissions 
                    WHERE agent_id = :var_agent_id AND agent_menu_id in
                    (
                        SELECT id FROM agent_menus 
                        WHERE code = :var_menu_code
                    )"),
                    ["var_agent_id" => $var_agent_id, "var_menu_code" => $var_menu_code]
                );

            $totalCount = count($result);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 
                [
                    "is_exist" => $totalCount > 0
                ]       
            ]);           

        } 
        catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }




    /**
     * agent/child/accounts
     * - 取得該代理的所有子帳號
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     *  "data" : {     
     *      
     *    }
     * }
     */
    public function get_child_accounts(Request $request)
    {
        try {
            $queryMy = Agent::where("id" , $request->ID)->first();

            if(!is_object($queryMy))
            {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $queryMyChilds = Agent::where("host_id" , $request->ID)
                ->orderBy('username')
                ->select("id" , "username")            
                ->get();

            $result = array();
            $result["is_not_child_account"] = $queryMy->host_id == 0;
            $result["childs"] = $queryMyChilds;
            $result["childCount"] =  count($queryMyChilds);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result
            ]);           

        } 
        catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }


    /**
     * agent/child/permission/sync
     * - 同步所有子帳號後台權限
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     *  "data" : {     
     *      
     *    }
     * }
     */
    public function sync_all_child_permission(Request $request)
    {
        try {
            $queryMy = Agent::where("id" , $request->ID)->first();

            if(!is_object($queryMy))
            {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            if($queryMy->host_id != 0)
            {
                return response()->json([
                    'code' => 9999,
                    'message' => __('error_message.9999'),
                    'data' => ""
                ]);
            }

            $queryMyChilds = Agent::where("host_id" , $request->ID)
                ->select("id")            
                ->get();

            //若沒有任何childs
            if(count($queryMyChilds) <= 0)
            {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                    'data' => ""
                ]);
            }

            $agentIDs = ArrayHelper::StdClass_ToSingleColumnArray($queryMyChilds , 'id');
 

            //因為不想刪掉再重建的方式，採用迴圈執行
            foreach($agentIDs as $agentID)
            {
                $sql = "INSERT INTO agent_permissions (agent_id , agent_menu_id , action , admin) "
                        ."SELECT ".$agentID." , id , '' , 0 from agent_menus WHERE code != 'agent_sync_permissiom' AND id in "
                        ."(SELECT agent_menu_id FROM agent_permissions WHERE agent_id = ".$request->ID.")"
                        ." AND id not in "
                        ."(SELECT agent_menu_id FROM agent_permissions WHERE agent_id = ".$agentID.")";

                DB::statement($sql);     
            }

            $repOfLog = new AgentOperationLogRepository();

            //寫入異動紀錄
            $agentIDs_text = implode(",", $agentIDs);        
            $repOfLog->insert( $request->ID , 0 , 'agent_permission_sync' , $agentIDs_text);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => ""
            ]);           

        } 
        catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }



    private function messages()
    {
        return [
            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'menus.required' => __('error_message.1058'),
            'menus.array' => __('error_message.1059')
        ];
    }
}