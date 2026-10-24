<?php

namespace App\Http\Controllers;

use App\REDIS;
use App\VendorMaintenance;
use Illuminate\Http\Request;
use Validator;

class VendorMaintenanceController extends Controller
{


    /**
     * vendorMaintenance/list
     * -遊戲商維護時間列表
     *
     * @bodyParam vendor_id integer 遊戲商系統編號 Example: 1
     * 
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商系統編號,ex:1",
     *            "start_time": "開始時間,ex:2019-01-01 00:00:00",
     *            "end_time": "結束時間,ex:2019-01-01 00:00:00",
     *            "status": "狀態0:停用1:使用中,ex:1",
     *            "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
     *            "admin": 1
     *          }
     *      ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new VendorMaintenance();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // Redis名稱
            $key = array(
                'vendor_maintenance',
                'list',
                $request->vendor_id,
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // Redis不存在就重新查詢
                $model = $model->where('vendor_id', $request->vendor_id)->whereIn('status', [0, 1]);
                $total = $model->count();
                $data = $model->orderBy('updated_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->get();
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
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
     * vendorMaintenance/add
     * -新增遊戲商維護時間
     *
     * @bodyParam vendor_id integer required 遊戲商系統編號 Example: 1
     * @bodyParam start_time dateTime required 開始日期 Example: 2019-03-01 00:00:00
     * @bodyParam end_time dateTime required 結束日期 Example: 2019-03-31 23:59:59
     * @bodyParam status boolean 狀態:0停用,1正常(預設為0) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $data = array();
            $model = new VendorMaintenance();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $data['vendor_id'] = $request->vendor_id;
            $data['start_time'] = $request->start_time;
            $data['end_time'] = $request->end_time;
            $data['status'] = isset($request->status) ? $request->status : 0;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'vendor_maintenance',
                    'list'
                ));
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->id
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
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
     * vendorMaintenance/edit
     * -編輯遊戲商維護時間
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam vendor_id integer required 遊戲商系統編號 Example: 1
     * @bodyParam start_time dateTime required 開始日期 Example: 2019-03-01 00:00:00
     * @bodyParam end_time dateTime required 結束日期 Example: 2019-03-31 23:59:59
     * @bodyParam status boolean 狀態:0停用,1正常(預設為0) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new VendorMaintenance();
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->where('id', $request->id)->whereIn('status', [0, 1])->first()) {
                $data = array();
                $data['vendor_id'] = isset($request->vendor_id) ? $request->vendor_id : $row->vendor_id;
                $data['start_time'] = $request->start_time ? $request->start_time : $row->start_time;
                $data['end_time'] = $request->end_time ? $request->end_time : $row->end_time;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'vendor_maintenance',
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
            } else {
                return response()->json([
                    'code' => 1213,
                    'message' => __('error_message.1213')
                ]);
            }
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
     * vendorMaintenance/delete
     * -刪除遊戲商維護時間
     *
     * @bodyParam id string required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function delete(Request $request)
    {
        try {
            $model = new VendorMaintenance();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_delete, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認記錄是否存在
            if ($model->find($request->id)) {
                // 更新記錄狀態
                if ($model->find($request->id)->update(array(
                    'status' => 2,
                    'admin' => $request->ID
                ))) {
                    $key = array(
                        'vendor_maintenance',
                    );
                    $redis = new REDIS();
                    $redis->delete($key);
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
                    'code' => 1213,
                    'message' => __('error_message.1213')
                ]);
            }
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

    private function messages()
    {
        return [
            'id.required' => __('error_message.1216'),
            'id.digits_between' => __('error_message.1217'),

            'vendor_id.required' => __('error_message.1401'),
            'vendor_id.digits_between' => __('error_message.1401'),

            'start_time.required' => __('error_message.1105'),
            'start_time.date_format' => __('error_message.1106'),

            'end_time.required' => __('error_message.1107'),
            'end_time.date_format' => __('error_message.1108'),
            'end_time.after' => __('error_message.1109'),

            'status.boolean' => __('error_message.1104')
        ];
    }
}