<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\REDIS;
use App\Helpers\Common;
use DB;
use App\AES;
use Validator;



/**
 * @group RD_Debug
 * 測試用、小工具
 */
class RD_DebugController extends Controller
{    


     /**
     * testCase/ip
     * - 測試取得IP
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        
     *    ]
     * }
     */
    public function TestGetIP(Request $request)
      {      
        try {         
            $ip = Common::getIp();
            return "your ip:".$ip;  
        } 
        catch (\Throwable $th) 
        {  
            return response()->json([
                  'code' => 2000,
                  'message' => "error",
                  'data' => "error"
            ]);
        }
         
    }


  /**
     * languages
     * - 純粹拿來測試某些function使用
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        
     *    ]
     * }
     */
    public function TestCase001(Request $request)
      {      
        try {          
            //28             
            $queryOrder = DB::table("orders")->where("id" , 777)->first();
            $queryAgentCashFlows = DB::table("agent_cash_flows")->where("id" , 20)->first();              


            $result = $this->send( $queryAgentCashFlows ,  $queryOrder  , "https://example.invalid/" , "https://example.invalid/");

            return "xxxxxxxxxxxxxx";
  
        } 
          catch (\Throwable $th) {
  
            return response()->json([
                  'code' => 2000,
                  'message' => "error",
                  'data' => "error"
            ]);
        }
         
    }


    public function send($agentCashFlow, $order, string $callbackUrl, string $returnUrl)
    {



        $qqqqqqqq1 = (int)"000666";
        $qqqqqqqq2 = (int)"Q0A006Z66";





      //  $order_type = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);   

        // D1=5003
        // D4=500304
        // Token=[REDACTED]        

        // D1 特約夥伴代
        // D2 收款種類       
        // D3 金額       
        // D4 代收種類代      
        // D5 銷帳編號       
        // D6 交易驗證碼 
        // D7 繳費期限 
        // D8 繳費期限逾期後是否失效
        

        //產生 yyMMdd + elg_order訂單號 的16碼 訂單號
        $order_no_16 = ((int)substr(date("Ymd", strtotime($order->created_at)) , 2 , 6)) * 10000000000 + $order->id;
        
        $D1 = "5003";
        $D2 = "1";
        $D3 = (int)$order->amount;
        $D4 = "500304";
        $D5 = $order_no_16;
        $D6 = "sign";
        $D7 = date("YmdHis");        
        $D8 = "1";


        $now =  date("Y-m-d H:i:s");

        $new_date1 = date("Y-m-d H:i:s", strtotime('+8 hours', strtotime($now )));
        $new_date2 = date("Y-m-d H:i:s", strtotime('+24 hours', strtotime($now )));




        $sign_P1 = $this->calculate_checkCode_P1($D1);
        $sign_P2 = $this->calculate_checkCode_P2($D5);
        $sign_P3 = $this->calculate_checkCode_P3($D3);

        //檢查碼
        $D6 = $sign_P1 * $sign_P2 * $sign_P3;

        $debug_info = $sign_P1."_".$sign_P2."_".$sign_P3;

        $transaction_code = sprintf("AsiaLife://D1=%s&D2=%s&D3=%s&D4=%s&D5=%s&D6=%s&D7=%s&D8=%s" ,
            $D1, $D2, $D3, $D4, $D5, $D6, $D7 ,$D8 );

        return ['type'=>21, 'url'=>'' , 'code'=>  $transaction_code , 'debug_info'=> $debug_info ];
    }



    private function calculate_checkCode_P1($D1_vendorID)
    {        
        $chars = str_split($D1_vendorID);
        $weighted_rate = 1;  //加權指數

        $totalSum = 0;

        foreach($chars as $char)
        {     
          $parseInt = (int)$char;
          $totalSum = $totalSum + $parseInt * $weighted_rate;
          $weighted_rate++;
        }  

        return $totalSum;
    }



    private function calculate_checkCode_P2($D5_transactionNo)
    {        
        $totalSum = 0;
      
        $charsOfOrderNo = str_split($D5_transactionNo);
        $weighted_rate_P2 = 16;  //加權指數
        
        foreach($charsOfOrderNo as $char)
        {     
          $parseInt = (int)$char;
          $totalSum = $totalSum + $parseInt * $weighted_rate_P2;
          $weighted_rate_P2--;
        }    

        return $totalSum;
    }



    private function calculate_checkCode_P3($D3_amount)
    {         
        $totalSum = 0;      
        $amount = (int)$D3_amount;        

        //將金額轉成6位數 前面補0
        $amountPadLeft = str_pad($amount ,6,"0",STR_PAD_LEFT);

        $charsOfAmountText = str_split($amountPadLeft);
        $weighted_rate_P3 = 1;  //加權指數

     
        foreach($charsOfAmountText as $char)
        {     
          $parseInt = (int)$char;
          $totalSum = $totalSum + $parseInt * $weighted_rate_P3;
          $weighted_rate_P3++;
        }    

        return $totalSum;
    }




    //將傳入的 params & key 解密
	public function SQLBinding(Request $request)
    {      
        try {            
            $sql = $request["query"]; 
            $bindings = $request->bindings; 

            $sql_before_replace = str_replace('%' , 'REPLACE_TEMP' ,  $sql);
            $sql_before_replace = str_replace('?' , '%s' ,  $sql_before_replace);
  
            $newArray = array();

            foreach ($bindings as $value) 
            {
                array_push($newArray  , "'".$value."'" );
            }

            $sql_withParameter = vsprintf($sql_before_replace ,   $newArray);
            $sql_withParameter = str_replace('REPLACE_TEMP' , '%' ,  $sql_withParameter);

            //自動換行 讓轉出來的SQL不要一團亂
            $sql_withParameter = str_replace('group by' , "\r\ngroup by",  $sql_withParameter);
            $sql_withParameter = str_replace('order by' , "\r\norder by",  $sql_withParameter);
            $sql_withParameter = str_replace('from' , "\r\nfrom",  $sql_withParameter);
            $sql_withParameter = str_replace('left join' , "\r\nleft join",  $sql_withParameter);
            $sql_withParameter = str_replace('inner join' , "\r\ninner join",  $sql_withParameter);
            $sql_withParameter = str_replace('where' , "\r\nwhere",  $sql_withParameter);
            $sql_withParameter = str_replace('union' , "\r\nunion",  $sql_withParameter);
        
            return $sql_withParameter;

        } 
        catch (\Throwable $th) {

            return response()->json([
                'code' => 2000,
                'message' => "error",
                'data' => "error"
            ]);
        }
       
    }


    private function getQueryStringOrEnv(Request $request , $key)
    {
        $targetValue = $request->query($key);
        $targetValue =  isset($targetValue) ? $targetValue : env($key);
        return $targetValue;
    }
    
    //將傳入的 params & key 解密
	public function Decrypt(Request $request)
	{         
        $API_AES_KEY = $this-> getQueryStringOrEnv($request , "API_AES_KEY");
        $API_IV = $this->getQueryStringOrEnv($request , "API_IV");

        $json_content = $request->getContent();
        $myObject = json_decode($json_content, true);

        $paramsValue = $myObject["params"];

        $params = str_replace(' ', '+', $paramsValue);
        $params = str_replace('\\', '', $params);
        
        // 5.解密param
        $aes = new AES($API_AES_KEY, $API_IV);
        $decryptResult = json_decode($aes->decrypt($params), true);        

        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $decryptResult
        ]);
    }
    

    //產生加 密後的MD5
	public function Encrypt(Request $request)
	{             
        $API_AES_KEY = $this-> getQueryStringOrEnv($request , "API_AES_KEY");
        $API_IV = $this->getQueryStringOrEnv($request , "API_IV");  
        $API_MD5_KEY = $this->getQueryStringOrEnv($request , "API_MD5_KEY");

        $json_content = $request->getContent();      
        $aes = new AES($API_AES_KEY,   $API_IV);
        $encryptParams = $aes->encrypt($json_content);     
        $key = md5($encryptParams . $API_MD5_KEY );
        
        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => [
                'key' =>  $key,
                'params' => $encryptParams
            ]
        ]);
    }
    

}
             