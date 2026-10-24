<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use App\AgentPermission;
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
