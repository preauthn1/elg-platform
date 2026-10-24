<?php

namespace App\Http\Controllers;

use App\Vendor;
use App\Agent;
use App\AgentVendor;
use App\AgentVendorDisable;
use App\Helpers\Common;
use App\REDIS;
use DB;
use Illuminate\Http\Request;
use Validator;

class AgentVendorController extends Controller
{

    /**
     * agentVendor/list
     * -遊戲商設定列表
     *
     * @bodyParam agent_id integer 遊戲商編號(如果沒有傳就是呼叫的那位代理) Example: 1
     * 
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *          "id": "遊戲商系統編號,ex:1",
     *          "name": "遊戲商名稱,ex:AG",
     *          "image": "遊戲商圖片,ex:http://xxx.xxx.xx/xxxxx.jpg",
     *          "status": "狀態:0未啟用，1啟用",
     *          "backend_show": "代理後台顯示:0不顯示，1顯示",
     *          "vendor_status": "遊戲商狀態:1開放中，2開發中,ex:1"
     *        },
     *        {
     *          "id": "遊戲商系統編號,ex:2",
     *          "name": "遊戲商名稱,ex:Joker",
     *          "image": "遊戲商圖片,ex:http://xxx.xxx.xx/xxxxx.jpg",
     *          "status": 0,
     *          "backend_show": "1",
     *          "vendor_status": 1
     *        }
     *    ]
     * }
     */
    function list(Request $request)
    {
        try {
            $model = new Vendor();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $agent_id = isset($request->agent_id) ? $request->agent_id : $request->ID;
            return response()->json($this->agentVenors($agent_id));
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
     * -遊戲商設定
     *
     * @bodyParam agent_id integer 營運商編號(如果沒有傳就是呼叫的那位代理) Example: 1
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam status boolean  狀態0:停用,1:啟用  Example:1
     * @bodyParam backend_show boolean  代理後台顯示狀態0:停用,1:啟用  Example:1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function setting(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new Vendor();
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
            //只能改自己或自己下線的
            if ($this->isChild($request->HOST, $request->agent_id) || $request->HOST == $request->agent_id || $request->agent_id == '') {
                // 寫入新的
                $agent_id = isset($request->agent_id) ? $request->agent_id : $request->HOST;
                $data = array();
                $data['vendor_id'] = $request->vendor_id;
                if (isset($request->backend_show)) {
                    $data['backend_show'] = $request->backend_show;
                }
                if (isset($request->status)) {
                    $data['status'] = $request->status;
                }
                $data['admin'] = $request->ID;
                $model = new AgentVendor();
                // 查詢是否存在
                $rows = $model->where('agent_id',  $agent_id)
                    ->where('vendor_id', $data['vendor_id'])
                    ->get();
                if (count($rows) == 0) {
                    //如果之前沒有記錄，要先全部寫入
                    $agent_vendors = $this->agentVenors($agent_id);
                    if (is_array($agent_vendors) && $agent_vendors['code'] == 1000) {
                        $info = array();
                        $info['agent_id'] = $agent_id;
                        $info['admin'] = $request->ID;
                        foreach ($agent_vendors['data'] as $agent_vendor) {
                            $info['vendor_id'] = $agent_vendor->id;
                            $info['vendor_info_id'] = $agent_vendor->vendor_info_id;
                            $info['backend_show'] = $agent_vendor->backend_show;
                            $info['status'] = $agent_vendor->status;
                            $model->create($info);
                        }
                    }
                }
                // 如果是關閉的話留下記錄，避免上線開啟時，會自動開放
                if ($data['status'] == 1) {
                    // 開啟時刪除flag
                    AgentVendorDisable::where('agent_id', $agent_id)->where('vendor_id', $request->vendor_id)->delete();
                } else if ($data['status'] == 0) {
                    // 關閉時新增flag
                    $agent_vendor_disable = AgentVendorDisable::where('agent_id', $agent_id)->where('vendor_id', $request->vendor_id)->first();
                    if (!is_object($agent_vendor_disable)) {
                        AgentVendorDisable::create(['agent_id' => $agent_id, 'vendor_id' => $request->vendor_id]);
                    }
                }

                //更改自己跟下線的記錄(上線關閉，下線全關)(上線開啟，下線照原本的設定開關)
                $agents = Agent::where('hierarchy', 'like', '%,' . $agent_id . ',%')->pluck('id');
                if (is_object($agents)) {
                    $data['updated_at'] = date('Y-m-d H:i:s');

                    //上線遊戲商開啟時避免開到下線原本關閉的
                    if ($data['status'] == 1) {
                        //查詢下線自行關閉的名單
                        $diable_ids = AgentVendorDisable::where('vendor_id', $request->vendor_id)->pluck('agent_id');
                        $model = $model->whereNotIn('agent_id', $diable_ids);
                    }
                    $result = $model->whereIn('agent_id', $agents)
                        ->where('vendor_id', $request->vendor_id)
                        ->update($data);
                }
                if ($result) {
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
            } else {
                //不能改其他代理的
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
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


    private function agentVenors($agentId)
    {
        // 查代理資訊
        $agent = Agent::find($agentId);
        if (is_object($agent)) {
            // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的遊戲
            $array = explode(',', $agent->hierarchy);
            //頭尾兩個會是無效的
            for ($i = 1; $i < (count($array) - 1); $i++) {
            //從最上線開始找
            //for($i = (count($array) - 1); $i > 0; $i++){
                $agent_id = $array[$i];
                $data = DB::table('vendors as v')
                    ->leftJoin('agent_vendors as a', function ($join) use ($agent_id) {
                        $join->on('v.id', '=', 'a.vendor_id')
                            ->where('a.agent_id', $agent_id);
                    })
                    ->where('v.status', '>', 0)
                    ->whereNotNull('a.id')
                    ->select('v.id', 'v.name', 'v.image', 'a.status', 'a.backend_show', 'a.vendor_info_id', 'v.status as vendor_status')
                    ->orderBy('v.id', 'asc')
                    ->get();
                if (count($data) > 0) {
                    return [
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ];
                }
            }
            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => []
            ];
        } else {
            return [
                'code' => 1050,
                'message' => __('error_message.1050'),
            ];
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