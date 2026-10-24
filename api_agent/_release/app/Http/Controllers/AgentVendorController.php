<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vendor;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\AgentVendorDisable;
use App\Helpers\Common;
use App\REDIS;
use DB;
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

    //直接打API確認代理的遊戲商狀態
    public function list2(Request $request)
    {
        try {
            $agent = Agent::where('username', $request->username)->first();
            if (is_object($agent)) {
                return response()->json($this->getAgentVenors($agent->id));
            } else {
                return response()->json([
                    'code' => '1050',
                    'message' => __('error_message.1050'),
                    'username' => $request->username
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
                    AgentVendorDisable::where('agent_id', $agent_id)
                        ->where('vendor_id', $request->vendor_id)
                        ->delete();
                } else if ($data['status'] == 0) {
                    // 關閉時新增flag
                    $agent_vendor_disable = AgentVendorDisable::where('agent_id', $agent_id)
                        ->where('vendor_id', $request->vendor_id)
                        ->first();
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


     /**
     * agentVendor/force_create
     * -強制建立某個代理的可支援遊戲商(無視上級代理)
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
    public function force_create(Request $request)
    {
        try {
          
            $model = new Vendor();
            $model_agentVendor = new AgentVendor();
            
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

            $target_agent_id = $request->agent_id;
            $target_vendor_id = $request->vendor_id;


            $queryAgent = Agent::where("id" , $target_agent_id)->first();

            //查出所有遊戲商 (以config設置的templateID當作基準)
            $query_template_vendor = $this->get_template_agent_vendors();       

            //查出該代理當前設定
            $queryAgentVendors = AgentVendor::where('agent_id',  $request->agent_id)
                ->select("agent_id" , "vendor_id" , "status")
                ->get();

             //查出上級代理的設定(樹狀查詢)
            $queryParentAgentVendors = $this->agentVenorsTreeSearch($queryAgent->parent);

            
            $existAgentVendorIDs = $queryAgentVendors->pluck("vendor_id");


            //step 1. 查出資料不存在的、並insert之
            $needInsert = $query_template_vendor->whereNotIn("vendor_id" ,   $existAgentVendorIDs);


            foreach ($needInsert as $insertItem) {

                $insertModel = array();
                $insertModel["agent_id"] = $target_agent_id;
                $insertModel["admin"] =  $request->ID;
                $insertModel["vendor_id"] = $insertItem->vendor_id;
                $insertModel["vendor_info_id"] = 0;
                $insertModel["backend_show"] = 0;
                $insertModel["status"] = 0;

                //首次建立，以上級的設定為基準
                $matchParentSetting = $queryParentAgentVendors->where("vendor_id" , $insertItem->vendor_id)->first();

                if(is_object($matchParentSetting))
                {
                    $insertModel["backend_show"] = $matchParentSetting->backend_show;
                    $insertModel["status"] = $matchParentSetting->status;
                    $insertModel["vendor_info_id"] = $matchParentSetting->vendor_info_id;
                }
                else
                {
                    //如果上級沒有設定此遊戲商，以預設代理當做基準
                    $match_template_setting = $query_template_vendor->where("vendor_id" , $insertItem->id)->first();
                    if(is_object($match_template_setting))
                    {
                        $insertModel["vendor_info_id"] = $match_template_setting->vendor_info_id;
                    }
                }

                AgentVendor::Create($insertModel);
            }

            //step 2. update本次目標 
            AgentVendor::where('agent_id',  $target_agent_id)
                ->where("vendor_id" , $target_vendor_id )
                ->update(
                    [
                        'status' => $request->status,
                        'backend_show' => $request->backend_show
                    ]);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' =>  $needInsert
            ]);


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


/**
     * agentVendor/template
     * - 取得當做樣板基準的預設代理資料
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function template_agent_vendors(Request $request)
    {
        try {
          
            $query_template_vendor =  $this->get_template_agent_vendors();       

            $queryAllVendors =  Vendor::all();            

            $result_list = array();

            foreach ($query_template_vendor as $agent_vendor) 
            {
                $matchVendor = $queryAllVendors->where("id" ,  $agent_vendor->vendor_id)->first();

                $new_item = 
                [
                    'agent_id' => $agent_vendor->agent_id,
                    'vendor_id' => $agent_vendor->vendor_id,
                    'vendor_info_id' => $agent_vendor->vendor_info_id,
                    'name' => $matchVendor->name,
                    'image' => $matchVendor->image
                ];

                array_push($result_list , $new_item);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' =>  $result_list
            ]);


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

    /**
     * agentVendor/hasAgentVendorList
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
    public function hasAgentVendorList(Request $request)
    {
        try {
//            $model = new AgentVendor();
//            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
//            if (!$validator->passes()) {
//                return response()->json([
//                    'code' => 2002,
//                    'message' => __('error_message.2002'),
//                    'data' => $validator->errors()
//                        ->all()
//                ]);
//            }

            $result = DB::table('agents as a')
                ->leftJoin('agent_vendors as av', function ($join) use ($request) {
                    $join->on('a.id', '=', 'av.agent_id')
                        ->where('av.vendor_id', $request->vendor_id);
                })
                ->leftJoin('vendors as v', 'v.id', 'av.vendor_id')
                ->where('a.parent', '<=', 1)
                ->where('a.host_id', 0)
                ->select('a.id as agent_id',
                    'a.username',
                    DB::raw('CASE WHEN  av.status is not null THEN av.status ELSE 0 END as vendor_status'))
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

    private function get_template_agent_vendors()
    {
        $config_template_agentID = env('AGENT_VENDOR_TEMPLATE_AGENT_ID');

        $query_template_vendor =  AgentVendor::where('agent_id',  $config_template_agentID)->get();   
        
        return $query_template_vendor;
    }



    private function agentVenorsTreeSearch($agentId)
    {
        // 查代理資訊
        $agent = Agent::find($agentId);
        if (is_object($agent)) {
            // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的遊戲
            $array = explode(',', $agent->hierarchy);
            //頭尾兩個會是無效的
            for ($i = 1; $i < (count($array) - 1); $i++) {
                //從最上線開始找
                $agent_id = $array[$i];
                $data = DB::table('vendors as v')
                    ->join('agent_vendors as a', function ($join) use ($agent_id) {
                        $join->on('v.id', 'a.vendor_id')
                            ->where('a.agent_id', $agent_id);
                    })
                    ->join('vendor_infos as vi', function ($join) {
                      $join->on('vi.id', 'a.vendor_info_id')
                          ->where('vi.status', 1);
                    })
                    //->where('v.status', '>', 0)  //移除此邏輯 by eks指示
                    ->orderBy('v.id', 'asc')
                    ->select(
                        'v.id',
                        'v.id as vendor_id',
                        'v.name',
                        'v.image',
                        'a.status',
                        'a.backend_show',
                        'a.vendor_info_id',
                        'v.status as vendor_status'
                    )
                    ->get();

                if (count($data) > 0) {
                    return $data;
                }
            }
            return [];
        } else {
           return [];
        }
    }


    private function agentVenors($agentId)
    {
        // 查代理資訊
        $agent = Agent::find($agentId);
        if (is_object($agent)) {

            //樹狀結構搜尋
            $query = $this->agentVenorsTreeSearch($agentId);

            if (count($query) > 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $query
                ];
            }

            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => []
            ];
        } 
        else 
        {
            return [
                'code' => 1050,
                'message' => __('error_message.1050'),
            ];
        }
    }

    //新的抓代理列表方式
    private function getAgentVenors($agentId)
    {
        // 查代理資訊
        $agent = Agent::find($agentId);
        if (is_object($agent)) {
            //最上層
            if ($agent->parent == 0) {
                $model = DB::table('vendors AS v')
                    ->join('agent_vendors AS a', function ($join) use ($agentId) {
                        $join->on('v.id', 'a.vendor_id')
                            ->where('a.agent_id', $agentId);
                    })
                    ->select(
                        'v.id',
                        'v.name',
                        'v.image',
                        'a.status',
                        'a.backend_show',
                        'a.vendor_info_id',
                        DB::raw('v.status as vendor_status')
                    );
            } else {
                // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的遊戲
                $array = array_slice(explode(',', $agent->hierarchy), 2, -1);
                $main = DB::table('vendors as v')
                    ->join('agent_vendors as a', function ($join) use ($array) {
                        $join->on('v.id', 'a.vendor_id')
                            ->whereIn('a.agent_id', $array);
                    })
                    ->groupBy('v.id')
                    ->select(
                        'v.id',
                        'v.name',
                        'v.image',
                        DB::raw('MIN(a.status) AS status'),
                        DB::raw('MIN(a.backend_show) AS backend_show'),
                        'a.vendor_info_id',
                        DB::raw('v.status AS vendor_status')
                    );
                $sub = DB::table('vendors as v')
                    ->join('agent_vendors as a', function ($join) use ($agentId) {
                        $join->on('v.id', 'a.vendor_id')
                            ->where('a.agent_id', $agentId);
                    })
                    ->select('v.id', 'a.status', 'a.backend_show', 'a.vendor_info_id');
                $model = DB::table(DB::raw('(' . $main->toSql(). ') as y'))
                    ->leftJoin(DB::raw('(' . $sub->toSql() . ') as z'), 'y.id', 'z.id')
                    ->select(
                        'y.id',
                        'y.name',
                        'y.image',
                        DB::raw('CASE WHEN y.status = 0 THEN 3 WHEN y.status = 1 AND z.status = 0 THEN 2 ELSE 1 END AS status'),
                        DB::raw('CASE WHEN z.backend_show IS NOT NULL THEN z.backend_show ELSE y.backend_show END AS backend_show'),
                        DB::raw('CASE WHEN z.vendor_info_id IS NOT NULL THEN z.vendor_info_id ELSE y.vendor_info_id END AS vendor_info_id'),
                        DB::raw('y.status AS vendor_status')
                    );
                // 合并绑定参数
                $model->mergeBindings($main)->mergeBindings($sub);
            }
            $data = $model->get();
            if (count($data) > 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ];
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

    /**
     * agentVendor/settingBackend
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
    public function settingBackend(Request $request)
    {
        try {
            DB::beginTransaction();
//            $model = new AgentVendor();
//            // 驗證資料是否正確
//            $validator = Validator::make($request->all(), $model->rules_setting, $this->messages());
//            if (!$validator->passes()) {
//                return response()->json([
//                    'code' => 2002,
//                    'message' => __('error_message.2002'),
//                    'data' => $validator->errors()
//                        ->all()
//                ]);
//            }
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