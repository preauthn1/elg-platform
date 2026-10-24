<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\SlackHelper;
use App\Helpers\Common;
use App\Models\DebugInfoLog;
use Validator;
use DB;

/**
 * @group Advertisements
 */
class DebugInfoController extends Controller
{

    /**
     * debug/write_log
     * - 提供前端寫一些log資訊用的API
     *     
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": null
     * }
     */
    public function write_log(Request $request)
    {        
        if(!isset($request->category) || !isset($request->msg)|| !isset($request->info_level))
        {
            return response()->json([
                'code' => 1000,
                'message' => "invalid_parameter"
            ]);
        }

        $userIP = Common::getIp();
     
        $insert = array();

        $insert ["category"] = $request->category;
        $insert ["info_level"] = $request->info_level;
        $insert ["msg"] = $request->msg;
        $insert ["memo_1"] = $request->memo_1;
        $insert ["memo_2"] = $request->memo_2;
        $insert ["number_value_1"] = $request->number_value_1;
        $insert ["number_value_2"] = $request->number_value_2;
        $insert ["user_ip"] = $userIP;
        $insert ["user_identity"] = $request->ID;

        //寫入資料
        DebugInfoLog::Create($insert);

        if($request->is_notify)
        {   
            $slack_msg = "action:".$request->category."、userID:".$request->ID."、ip:".$userIP."、msg:".$request->msg;
            SlackHelper::SendMessage_F2E_Notify($slack_msg);
        }

        return response()->json([
            'code' => 1000,
            'message' => "success",
            'data' => $userIP
        ]);
    }

    private function messages()
    {
        return [
            'path_id.required' => __('error_message.1103'),
            'path_id.digits_between' => __('error_message.1104')
        ];
    }
}