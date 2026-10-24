<?php

namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use App\Models\Vendor;
use App\Models\VendorMaintenance;
use App\Models\Agent;
use App\REDIS;
use Validator;
use DB;

class VendorMaintenancesController extends Controller
{

    /**
     * vendor_maintenances/list
     * -遊戲商維護設定列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          "id": 1,
     *          "vendor_id": 10,
     *          "type": 1,
     *          "start_time": "2020-06-11 15:30:11",
     *          "end_time": "2020-06-11 17:30:21",
     *          "status": 0,
     *          "created_at": "2019-12-10 07:31:01",
     *          "updated_at": "2020-06-11 08:24:11",
     *          "admin": 0,
     *          "username": 'admin',
     *          "vendor_name": "Mega888"
     *       }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $table_valid = new VendorMaintenance();
            $validator = Validator::make($request->all(), $table_valid->rules_search, $this->messages());

            if (!$validator->passes()) {
                return $this->get_valid_fail_response($validator);
            }

            $query = DB::table('vendor_maintenances as m')
                ->join('vendors as v', 'm.vendor_id', 'v.id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.agent_id')
                        ->where('a.hierarchy_path', 'like', $request->HIERARCHY_PATH . '%');
                })
                ->leftJoin('agents as u', 'u.id', 'm.admin')
                ->leftJoin('agents as e', 'e.id', 'm.exclude_agent_id');

            $totalCount = $query->count();

            $result = $query->orderBy('m.id', 'desc')
                ->select(
                    'm.*',
                    'a.username as agent_username',
                    'e.username as exclude_agent_username',
                    DB::raw("CASE m.admin WHEN 0 THEN 'system' ELSE u.username END AS username"),
                    'v.name as vendor_name'
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result,
                'pagination' => $this->getPagination($totalCount, $request->per_page, $request->page)
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
     * vendor_maintenances/view
     * - 遊戲商維護-單筆詳細資料
     *
     * @bodyParam id integer required 會員系統編號 Example: 1
     * @response {
     *   "id": 2,
     *   "vendor_id": 1,
     *   "type": 2,
     *   "start_time": "2020-07-01 04:03:00",
     *   "end_time": "2020-07-02 07:14:00",
     *   "status": 0,
     *   "created_at": "2020-07-07 03:16:32",
     *   "updated_at": "2020-07-07 04:07:39",
     *   "admin": 4249
     * }
     */
    public function view(Request $request)
    {
        try {
            $model = new VendorMaintenance();

            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());

            if (!$validator->passes()) {
                return $this->get_valid_fail_response($validator);
            }

            $agent = Agent::find($request->HOST)->first();

            $query = DB::table('vendor_maintenances as vm')
                ->join('agents as a', function ($join) use ($agent) {
                    $join->on('a.id', 'vm.agent_id')
                        ->where('a.hierarchy_path', 'like', $agent->hierarchy_path . '%');
                })
                ->leftJoin('agents as e', 'e.id', 'vm.exclude_agent_id')
                ->where('vm.id', $request->id)
                ->select(
                    'vm.id',
                    'vm.vendor_id',
                    'vm.type',
                    'vm.start_time',
                    'vm.end_time',
                    'vm.agent_id',
                    'vm.status',
                    'vm.created_at',
                    'vm.updated_at',
                    'vm.admin',
                    'a.id as agent_id',
                    'a.username as agent_name',
                    DB::raw('IFNULL(e.id, 0) as exclude_agent_id'),
                    DB::raw('IFNULL(e.username, "") as exclude_agent_name')
                )
                ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $query 
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
     * vendor_maintenances/update
     * - 遊戲商維護-新增/編輯
     *
     * @bodyParam id integer required 會員系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     * 
     *    }
     * }
     */
    public function update(Request $request)
    {
        try {
            $target_table = new VendorMaintenance();
            $validator = Validator::make($request->all(), $target_table->rules_create, $this->messages());

            if (!$validator->passes()) {
                return $this->get_valid_fail_response($validator);
            }    

            $is_update = isset($request->update_id) && $request->update_id != "";

            $data = array();
            $data['vendor_id'] = $request->vendor_id;
            $data['type'] = $request->type;
            $data['start_time'] = $this-> convertToUTC( $request->start_time);
            $data['end_time'] = $this-> convertToUTC( $request->end_time);
            $data['agent_id'] = $request->agent_id;
            $data['exclude_agent_id'] = $request->exclude_agent_id;
            $data['status'] = $request->status;
            $data['admin'] = $request->ID;

            $result = ($is_update)
                ? $this->update_maintenance($request->update_id, $data)
                : $this->create_maintenance($data);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 0
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

    private function create_maintenance($data)
    {
        return VendorMaintenance::Create($data);
    }

    private function update_maintenance($id, $data)
    {
        $exist_data = VendorMaintenance::where('id', $id)->first();
        return $exist_data->update($data);
    }

    private function messages()
    {
        return [
            'member_id.required' => __('error_message.1303'),
            'member_id.digits_between' => __('error_message.1303'),

            'vendor_id.required' => __('error_message.1504'),
            'vendor_id.digits_between' => __('error_message.1505'),
        ];
    }
}