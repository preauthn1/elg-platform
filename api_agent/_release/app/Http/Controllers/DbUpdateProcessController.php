<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\AgentPermission;
use App\Models\AgentMenu;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Helpers\Common;
use App\Models\AgentOperateLogs;
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\AgentRepository;
use App\Services\CSDataSyncService;

class DbUpdateProcessController extends Controller
{   

    /* 一次性的資料更新 */
    public function UpdateAgentHierarchyPath(Request $request)
    {
        try {         

            $rep = new AgentRepository();
 
            return response()->json([
                'code' => 9999,
                'message' => "逐筆迴圈更新會導致agents資料表lock，建議不要亂使用，未來建議先insert到一張temp表，然後再join update",
                'data' => "請參考訊息"
            ]);


            $queryAll = DB::table("agents")->select("id" , "hierarchy")->get();

            foreach ($queryAll as $item) 
            {               
                $new_path = $rep->parseToHierarchyPathFormat($item->hierarchy);

                $update = array();
                $update["hierarchy_path"] = $new_path;

                DB::table("agents")->where("id" , $item->id)->update($update);
            }
            
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => ""
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