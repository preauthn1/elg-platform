<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use App\UserPermission;
use Validator;
use DB;

class UserPermissionController extends Controller
{

    /**
     * userPermission/setting
     * -設定管理者權限
     *
     * @bodyParam user_id integer required 管理者系統編號 Example: 1
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
            $model = new UserPermission();
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
            // 確認是否有權限
            if ($this->isChild($request->ID, $request->user_id) || $request->ID == $request->user_id) {
                // 刪除舊有的權限
                $model->where('user_id', $request->user_id)->delete();
                // 新增權限
                $data = array();
                $data['user_id'] = $request->user_id;
                $data['admin'] = $request->ID;
                foreach ($request->menus as $key => $action) {
                    // 如果選單編號不是數字直接報錯
                    if (! is_numeric($key)) {
                        return response()->json([
                            'code' => 1060,
                            'message' => __('error_message.1060')
                        ]);
                    }
                    $data['menu_id'] = $key;
                    $data['action'] = $action;
                    $result = $model->create($data);
                }
                DB::commit();
                if ($result) {
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
            } else {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
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
            'user_id.required' => __('error_message.1057'),
            'user_id.integer' => __('error_message.1052'),
            'menus.required' => __('error_message.1052'),
            'menus.integer' => __('error_message.1052')
        ];
    }
}
