<?php
namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use App\REDIS;
use DB;
use App\Helpers\Common;

class OrderTypeController extends Controller
{

    /**
     * orderType/list
     * 訂單類型列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:AliPay"
     *        },
     *        {
     *            "id": 2,
     *            "name": "UnionPay"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $data = DB::table('agents as a')
                ->join('agent_cash_flows as acf', function ($join) {
                    $join->on('acf.agent_group_id', 'a.cash_flow_group_id')
                        ->where('acf.status', 1);
                })
                ->join('cash_flows as cf', function ($join) {
                    $join->on('cf.id', 'acf.cash_flow_id')
                        ->where('cf.status', 1);
                })
                ->join('order_types as ot', function ($join) {
                    $join->on('ot.cash_flow_id', 'acf.cash_flow_id')
                        ->where('ot.status', 1);
                })
                ->leftJoin('order_type_names as otn', function ($join) use ($request) {
                    $join->on('otn.type_id', 'ot.id')
                        ->where('otn.language_id', $request->LANGUAGE);
                })
                ->where('a.id', $request->ID)
                ->orderBy('cf.id')
                ->orderBy('ot.cash_flow_id')
                ->select(
                    'ot.id',
                    DB::raw('cf.id as cf_id'),
                    DB::raw('cf.name as cf_name'),
                    DB::raw('IFNULL(otn.name, ot.name) as name')
                )
                ->get();
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
     * orderType/paging-list
     * 訂單類型列表 (分頁)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     * 
     *    ]
     * }
     */
    public function paging_list(Request $request)
    {
        try {           

            $query = DB::table('order_types as ot')  
                        ->join("cash_flows as cf" , "ot.cash_flow_id" , "cf.id")   
                        ->select("ot.*" , "cf.name as cash_flow_name");

            if(Common::IsNotNullOrEmptyString($request->name))
            {
                $query = $query->where("ot.name" , 'like', '%' . $request->name . '%');
            }
                       
            $total = $query->count();

            $result = $query->skip(($request->page - 1) * $request->per_page)
                            ->take($request->per_page)
                            ->get();        

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result,                
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
     * orderType/update
     * 更新訂單類型
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": []
     * }
     */
    public function update(Request $request)
    {
        try {           

            $query = DB::table('order_types')     
                        ->where("id" , $request->id)   
                        ->first();       
                        
            if(!is_object($query))
            {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                ]);
            }
                        
            $update = array();
            $update["name"] = $request->name;
            $update["key"] = $request->key;
            $update["sorts"] = $request->sorts;            
            $update["status"] = $request->status; 
            $update["image_path"] = $request->image_path;

            DB::table('order_types')->where("id" , $request->id)->update($update);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
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


}