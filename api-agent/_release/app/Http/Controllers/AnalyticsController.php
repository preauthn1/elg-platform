<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\Analytics;
use Validator;
use App\Param;

class AnalyticsController extends Controller
{

    /**
     * analyticType/list
     * -第三方分析類型列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "類型編號,ex:1",
     *            "name": "類型名稱,ex:fb",
     *            "sort": "排序,ex:1"
     *        },
     *         {
     *            "id": 2,
     *            "type": "ga",
     *            "sort": 2
     *        }
     *    ]
     * }
     */
    public function type(Request $request)
    {
        try {
            $model = new Param();
            $key = array(
                'param',
                'analytics_type'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = $model->select('value as id', 'parameter as type', 'sort')->where('type', "analytics_type");
                $data = $data->get();
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
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
     * analytics/list
     * -第三方分析設定列表
     *
     * @bodyParam status boolean 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "type": "代碼,ex:1",
     *            "code": "名稱,ex:45121196152222635"
     *        },
     *         {
     *            "id": 2,
     *            "type": 2,
     *            "code": "86458966411"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Analytics();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'analytics',
                'list',
                $request->ID,
                $request->status
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = $model->select('id', 'type', 'code')->where('agent_id', $request->ID);
                if (isset($request->status)) {
                    $data->where('status', $request->status);
                }
                $data = $data->get();
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
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
     * analytics/setting
     * -設定第三方分析程式碼
     *
     * @bodyParam type integer required 系統編號 Example: 1
     * @bodyParam code string required 程式碼 Example: 86586453
     * @bodyParam status boolean required 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Analytics();
            // 驗證
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 確認是否有這類型
            if (Param::where('type', 'analytics_type')->where('value', $request->type)->First()) {
                // 驗證
                $data = array();
                $data['code'] = $request->code;
                $data['status'] = $request->status;
                $data['admin'] = $request->ID;
                // 查詢是否存在
                $rows = $model->where('agent_id', $request->ID)
                    ->where('type', $request->type)
                    ->get();
                if (count($rows) > 0) {
                    // 更新
                    $result = $model->where('agent_id', $request->ID)
                        ->where('type', $request->type)
                        ->update($data);
                } else {
                    // 新增
                    $data['agent_id'] = $request->ID;
                    $data['type'] = $request->type;
                    $result = $model->create($data);
                }
                if ($result) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'analytics',
                        'list'
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
                    'code' => 1503,
                    'message' => __('error_message.1503')
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
            'type.required' => __('error_message.1504'),
            'type.digits_between' => __('error_message.1505'),

            'code.required' => __('error_message.1506'),
            'code.between' => __('error_message.1507'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009')
        ];
    }
}
