<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vendor;
use App\REDIS;
use App\Helpers\Common;
use Validator;
use DB;
use App\AES;

class VendorBalanceController extends Controller
{


    /**
     * member/vendor/balance
     * - 嘗試取得用戶遊戲錢包金額
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *      
     * }
     */
    public function try_get_vendor_balance(Request $request)
    {
        try {

            $targetVendorID = $request->vendor_id;
            $targetMemberID = $request->member_id;

            // $targetVendorID = 24;
            // $targetMemberID = "47563";

            $utcNow = gmdate("Y-m-d H:i:s");

            //取得最新一筆token (如果用戶當前登入中，會使用跟他同一組)
            $queryLastToken = [REDACTED]"member_access_tokens")
                ->where("member_id" , $targetMemberID)
                ->where("expire_date" , ">" , $utcNow)
                ->orderBy("expire_date" , "DESC")
                ->first();
            
            $token = "";
         
            //如果當前沒有使用中的token 新建一個
            if(!is_object($queryLastToken))
            {
                $after_1_hours_utc =  gmdate('Y-m-d H:i:s', strtotime('+ 1 hours'));

                $token = [REDACTED];
                $insert = array();
                $insert["member_id"] = $targetMemberID ;
                $insert["token"] =  $token;
                $insert["expire_date"] = $after_1_hours_utc;
                $insert["admin"] = "0";

                DB::table("member_access_tokens")->insert($insert);                
            }
            else {
                $token = [REDACTED];
            }

            $post_param = array();
            $post_param["vendor_id"] = $targetVendorID;


            $json_content = json_encode($post_param);
       
            $domainUrl = env('F2E_API_URL');
            $result = $this->curlPost($domainUrl , 'api/getVendorBalance' , $json_content , $token);

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


    function GUID()
    {
        if (function_exists('com_create_guid') === true)
        {
            return trim(com_create_guid(), '{}');
        }

        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }


    function curlPost($domain , $url, $json_content = '', $token)
    {
     
        $API_AES_KEY = env("API_AES_KEY");
        $API_IV = env("API_IV");  
        $API_MD5_KEY = env("API_MD5_KEY");
        
        $aes = new AES($API_AES_KEY,   $API_IV);
        $encryptParams = $aes->encrypt($json_content);     
        $key = md5($encryptParams . $API_MD5_KEY );

        
        $postBody = [
            'key' =>  $key,
            'params' => $encryptParams
        ];            

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $domain. $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $header = array();
        $header[] = "Authorization: Bearer " . $token;
        $language = isset($_SESSION['language']) ? $_SESSION['language'] : 'en';
        $header[] = "Accept-Language: " . $language;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result, true);
    }


}