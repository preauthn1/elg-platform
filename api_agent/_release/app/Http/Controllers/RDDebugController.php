<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Member;
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
use App\Helpers\RandomHelper;
use App\ThirdPartyApi\ATPayIntegrationHelper;




class RDDebugController extends Controller
{   
    /* 測試某些function專用 */
    public function TestCase001(Request $request)
    {      
        $time_1 = gmdate('Y-m-d h:i:s');

        try {         

            DB::beginTransaction(); 
            
            $time_2 = gmdate('Y-m-d h:i:s');

            $balance = $this->getMemberBalance();

            $time_3 = gmdate('Y-m-d h:i:s');

            $update_amount = $balance + 222;

            //故意幾秒
            sleep(5);

            DB::table("members")
                ->where("id" , 47563)
                ->update([
                    'balance' => $update_amount
                ]);

            $after_balance = $this->getMemberBalance();

            DB::commit();

            $time_4 = gmdate('Y-m-d h:i:s');

            $msg = "執行結果:".$balance."｜".$update_amount."｜".$after_balance."｜時間：".$time_1."｜".$time_2."｜".$time_3."｜".$time_4;

            $this->SendMessage_F2E_Notify($msg);
          
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 
                [
                    'value1' => $balance,                    
                    'value2' => $update_amount,                    
                    'value3' => $after_balance
                ]
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


    private function getMemberBalance()
    {
        $query = DB::table("members")->where("id" , 47563)->lockForUpdate()->select("id" , "balance")->first();
        return $query->balance;
    }

    
    public static function SendMessage_F2E_Notify($msg)
    {        
        $targetUrl = "https://example.invalid/services/T011TNRPVP0/B01J98RAJHZ/iQ5vdMJrgJW6wCgnxkuzY3MN";
        self::SendMessage($targetUrl , $msg );
    }


    public static function SendMessage( $channel_url , $msg)
    {
        $post = array();
        $post['text'] = $msg;        


        $post_payload = json_encode($post);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $channel_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, array('payload' => $post_payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $header = array();
        $header['Content-Type'] = "application/json";        


        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);

        $result =  json_decode($result, true);

        return response()->json([
            'code' => 1000,
            'message' => "success"
        ]);
    }

}