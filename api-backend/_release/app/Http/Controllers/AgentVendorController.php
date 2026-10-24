<?php

namespace App\Http\Controllers;

use App\Vendor;
use App\Agent;
use App\AgentVendor;
use App\Helpers\Common;
use App\REDIS;
use DB;
use Illuminate\Http\Request;
use Validator;

class AgentVendorController extends Controller
{

    /**
     * agentVendor/list
     * -第一層代理遊戲商設定列表
     * 
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * 
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          "agent_id": "營運商系統編號,ex:1",
     *          "username": "營運商帳號,ex:test",
     *          "vendor_info_id": "此營運商使用的遊戲商設定編號,ex:1",
     *          "vendor_status": "此營運商的遊戲商狀態:0隱藏，1顯示,ex:1"
     *        },
     *        {
     *          "agent_id": 2,
     *          "username": "test",
     *          "vendor_info_id": 1,
     *          "vendor_status": 0
     *        }
     *    ]
     * }
     */
    function list(Request $request)
    {
        try {
            $model = new AgentVendor();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            //改用遊戲商設定編號
            // $result = DB::table('agents as a')
            //     ->leftJoin('agent_vendors as av', function ($join) use ($request) {
            //         $join->on('a.id', '=', 'av.agent_id')
            //             ->where('av.vendor_id', $request->vendor_id);
            //     })
            //     ->leftJoin('vendors as v', 'v.id', 'av.vendor_id')
            //     ->where('a.parent', '<=', 1)
            //     ->select('a.id as agent_id', 'a.username', 'av.vendor_info_id', 'av.status as vendor_status')
            //     ->orderBy('a.id', 'asc')
            //     ->get();

            $result = DB::table('agents as a')
                ->leftJoin('agent_vendors as av', function ($join) use ($request) {
                    $join->on('a.id', '=', 'av.agent_id')
                        ->where('av.vendor_id', $request->vendor_id);
                })
                ->leftJoin('vendors as v', 'v.id', 'av.vendor_id')
                ->where('a.parent', '<=', 1)
                ->select('a.id as agent_id', 'a.username', DB::raw('CASE WHEN  av.status is not null THEN av.status ELSE 0 END as vendor_status'))
                ->orderBy('a.id', 'asc')
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
     * agentVendor/setting
     * -代理遊戲商顯示設定
     *
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam vendor_info_id integer required 遊戲商設定編號 Example: 1
     * @bodyParam agent_id integer required 營運商編號 Example: 1
     * @bodyParam status boolean required 狀態0:隱藏,1:顯示  Example:1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function setting(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new AgentVendor();
            // 驗證資料是否正確
            $validator = Validator::make($request->all(), $model->rules_setting, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            if ($request->status == 1) {
                $data = array();
                $data['agent_id'] = $request->agent_id;
                $data['vendor_id'] = $request->vendor_id;
                $data['vendor_info_id'] = $request->vendor_info_id;
                $data['status'] = $request->status;
                $data['admin'] = $request->ID;
                $model = new AgentVendor();
                $rows = $model->where('agent_id', $request->agent_id)
                    ->where('vendor_id', $request->vendor_id)
                    ->first();
                if (!is_object($rows)) {
                    // 新增
                    $result = $model->create($data);
                } else {
                    $result = true;
                }
            } else {
                //不顯示就要刪除，營運商後台才不會看到(下線的也要一起刪除)
                $agents = Agent::where('hierarchy', 'like', '%,' . $request->agent_id . ',%')->pluck('id');
                $result = AgentVendor::where('vendor_id', $request->vendor_id)->whereIn('agent_id', $agents)->delete();
            }

            if ($result !== false) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'agentVendor'
                ));
                $redis->delete(array(
                    'vendor'
                ));
                DB::commit();
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
        } catch (\Illuminate\Database\QueryException $e) {
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
            'id.required' => __('error_message.1068'),
            'id.digits_between' => __('error_message.1069'),

            'vendor_id.required' => __('error_message.1504'),
            'vendor_id.digits_between' => __('error_message.1505'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009')
        ];
    }
}