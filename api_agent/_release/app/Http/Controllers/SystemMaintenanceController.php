<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Param;
use App\REDIS;
use Validator;
use App\Models\MaintenanceWhitelist;
use DB;

class SystemMaintenanceController extends Controller
{
    /**
     * system_maintenance/list
     * -後台維護白名單
     *
     * @bodyParam id integer required 訂單系統編號 Example: 1
     * @bodyParam type integer required 類別 Example: 1
     * @bodyParam value string required IP或代理帳號 Example: 0.0.0.0
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": 1,
     *            "type" : 1
     *            "value": "值,ex:1"
     *        },
     *        {
     *            "id" : 2,
     *            "type" : 2,
     *            "value" : "Admin"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new MaintenanceWhitelist();
            $model = $model->leftjoin('agents as a', 'a.id', 'maintenance_whitelists.value');
            if (isset($request->value) && $request->type != 0) {
                $model->where('maintenance_whitelists.type', $request->type);
            }

            if (isset($request->value) && $request->value != '') {
                if ($request->type == 1) {
                    $model->where('maintenance_whitelists.value', 'like', '%' . $request->value . '%');
                } else {
                    $value = array();
                    $agent = DB::table('agents')
                        ->where('username', 'like', '%' . $request->value . '%')
                        ->pluck('id')
                        ->toArray();
                    if (count($agent) > 0) {
                        $value = $agent;
                    }
                    if ($request->type == 0) {
                        $ip = DB::table('maintenance_whitelists')->where('value', 'like', '%' . $request->value . '%')->pluck('value');
                        array_push($value, $ip);
                    }

                    $model->whereIn('maintenance_whitelists.value', $value);
                }

            }

            $total = $model->count();

            $data = $model->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'maintenance_whitelists.id as id',
                    'maintenance_whitelists.type as type',
                    DB::raw('CASE WHEN maintenance_whitelists.type =1 THEN maintenance_whitelists.value WHEN maintenance_whitelists.type = 2 THEN a.username END as value')
                )
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
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
     * system_maintenance/add
     * -新增後台維護白名單
     *
     * @bodyParam order_id integer required 訂單系統編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "parameter": "代碼,ex:mission",
     *            "value": "值,ex:1"
     *        }
     *    ]
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new MaintenanceWhitelist();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $value = $request->value;
            if ($request->type == 2) {
                $agent = DB::table('agents')->where('username', $request->value)->first();
                if (!is_object($agent)) {
                    return response()->json([
                        'code' => 1005,
                        'message' => __('error_message.1005'),
                    ]);
                } else {
                    $value = $agent->id;
                }
            }
            $data = array();
            $data['type'] = $request->type;
            $data['value'] = $value;
            $data['admin'] = $request->ID;

            if ($model->create($data)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            } else {
                return response()->json([
                    'code' => 2004,
                    'message' => __('error_message.2004'),
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
     * system_maintenance/delete
     * -刪除後台維護白名單
     *
     * @bodyParam order_id integer required 訂單系統編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "parameter": "代碼,ex:mission",
     *            "value": "值,ex:1"
     *        }
     *    ]
     * }
     */
    public function delete(Request $request)
    {
        try {
            $model = new MaintenanceWhitelist();
            $validator = Validator::make($request->all(), $model->rules_delete, $this->messages());

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $row = $model->find($request->id);

            if ($row->delete()) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            } else {
                return response()->json([
                    'code' => 2004,
                    'message' => __('error_message.2004'),
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
     * system_maintenance/has_maintenance
     * -後台是否維護中
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "parameter": "代碼,ex:mission",
     *            "value": "值,ex:1"
     *        }
     *    ]
     * }
     */
    public function has_maintenance(Request $request)
    {
        try {
            $result = false;

            $has_maintenance = Param::where('type', 'system_maintenance')
                ->where('parameter', 'backend')
                ->where('value', 1)
                ->select('parameter', 'value')
                ->first();

            if (is_object($has_maintenance)) {
                $result = true;
            }

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
     * system_maintenance/has_maintenance
     * -後台是否維護中
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "parameter": "代碼,ex:mission",
     *            "value": "值,ex:1"
     *        }
     *    ]
     * }
     */
    public function backendMaintenanceToggleStatus(Request $request)
    {
        try {
            $maintenance = DB::table('params')
                ->where('type', 'system_maintenance')
                ->where('parameter', 'backend')
                ->first();

            $maintenance_status = Param::find($maintenance->id);
            if (is_object($maintenance_status)) {
                if ($maintenance_status->update(['value' => $request->status])) {
                    $code = 1000;
                    $message = __('error_message.1000');
                } else {
                    $code = 2001;
                    $message = __('error_message.2001');
                }
                $data = $maintenance_status->value;
            } else {
                $code = 4044;
                $message = __('error_message.4044');
                $data = 0;
            }

            return response()->json([
                'code' => $code,
                'message' => $message,
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

    private function messages()
    {
        return [
            'id.required' => __('error_message.1309'),
            'id.numeric' => __('error_message.1310'),
            'type.required' => __('error_message.1504'),
            'type.in' => __('error_message.1503'),
            'value.unique' => __('error_message.9991'),
        ];
    }
}
