<?php

namespace App\ThirdPartyApi;

use App\Helpers\RandomHelper;
use App\Models\WithdrawalOrder;
use App\Helpers\Common;
use App\ThirdPartyApi\Models\ThirdPartyApiPayoutResult;
use DB;
use \Exception;

class ATPayIntegrationHelper
{
        
    private static $cash_flow_id_ATPay = 11;



	public static function RequestWithdrawalOrder($withdrawal_orders_id)
    {
        $quertOrder = WithdrawalOrder::where("wo.id" ,  $withdrawal_orders_id)
            ->leftJoin("members as m" , "wo.member_id" , "m.id")
            ->select("wo.*" , "m.parent as agent_id")
            ->first();

        $agentID = $quertOrder->agent_id;

        $vendor_setting = self::getVendorApiSettings($agentID);

        if($vendor_setting == null || 
           !array_key_exists("api_domain_url" , $vendor_setting) || 
           !array_key_exists("withdrawal_api_url" , $vendor_setting) || 
           !array_key_exists("available_banks" , $vendor_setting))
        {
            $result = new ThirdPartyApiPayoutResult();
            $result->is_success = false;
            $result->third_party_response = "agent_cash_flow info json not set / not completed. (".$agentID.")";
            return $result;
        }

        $url = $vendor_setting["api_domain_url"]. $vendor_setting["withdrawal_api_url"];

        $notifyUrl = env("API_EXTERNAL_URL")."api/ATPay/withdrawal/callback";

        $transaction_no =  RandomHelper::getOrderNoByPrefix("AT");
        $bankCode = self::convertToATPayBankCode($quertOrder->bank_code , $vendor_setting["available_banks"]);


        $post = [            
            'pay_memberid' => $vendor_setting["vendor_id"],
            'pay_out_trade_no' => $transaction_no,
            'pay_money' =>  $quertOrder->amount,
            'pay_bankname' => $bankCode,
            'pay_subbranch' => "无",
            'pay_accountname' => $quertOrder->account_name,
            'pay_cardnumber' => "无",
            'pay_cardaccount' =>  $quertOrder->account_no,
            'pay_province' => "无",
            'pay_city' => "无",
            'pay_notifyurl' => $notifyUrl,
        ];

        //字母順序排序
        ksort($post);

        $form_body_text = "";

        $loopIndex = 0;

        //串成&拼接的文字
        foreach( $post as $key => $vale)
        {
            $loopIndex++;

            if($loopIndex == 1)
            {
                $form_body_text = $form_body_text.$key."=".$vale;
            }
            else 
            {                
                $form_body_text = $form_body_text."&".$key."=".$vale;
            }
        }

        $beforeMD5 = $form_body_text."&key=".$vendor_setting["api_secret_key"];

        //MD5加密簽
        $sign_value = strtoupper(md5($beforeMD5));

        //組出最後的postBody
        $form_body_text =   $form_body_text."&pay_md5sign=".$sign_value;      

        //發出API請求

        $api_response = self::curlPost($url, $form_body_text);
        $result = new ThirdPartyApiPayoutResult();

        if(array_key_exists("transaction_id", $api_response))
        {
            $result->third_party_order_no = $api_response["transaction_id"];
        }

        $result->is_success = $api_response["status"] == "success";
        $result->third_party_response = json_encode($api_response);

        $insert = array();
        $insert["cash_flow_id"] = self::$cash_flow_id_ATPay;
        $insert["withdrawal_order_id"] =  $quertOrder->id;
        $insert["transaction_no"] = $transaction_no;
        $insert["third_party_transaction_no"] = Common::IsNullOrEmptyString($result->third_party_order_no) ? "" : $result->third_party_order_no;      
        $insert["payout_order_request_body"] = $form_body_text;
        $insert["payout_order_response"] =  $result->third_party_response;
        $insert["is_success_payout"] = 0;

        if(!$result->is_success)
        {
            $insert["info_1"] = $api_response["msg"];
            $result->display_error_msg = $api_response["msg"];            
        }

        //寫入一筆代付請求紀錄
        DB::table("withdrawal_payout_records")->insert($insert);    
        return $result;
    }



    private static function convertToATPayBankCode($withdrawal_order_bank_code , $vendor_setting_support_banks)
    {
        //target bankCode 轉成 三方的bankCode
        foreach ($vendor_setting_support_banks as $each_bank) 
        {               
            if($each_bank["BankCode_ELG"] == $withdrawal_order_bank_code)
            {
                return $each_bank["BankCode_Third"];
            }
        }

        return "";
    }



    private static function curlPost($url , $fomr_body)
    {
        Common::log_old("0" , $fomr_body , "ATPayIntegrationHelper_Before");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fomr_body);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/x-www-form-urlencoded'
        ));

        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);

        curl_close($ch);
        $result = json_decode($result, true);

        Common::log_old("0" , $result , "ATPayIntegrationHelper_After");
        return $result;
    }


    private static function getVendorApiSettings($agentID)
    {
        $query = DB::table("agents as a")
            ->leftJoin("agent_cash_flows as acf" , "a.cash_flow_group_id" , "acf.agent_group_id")
            ->where("a.id" , $agentID)
            ->where("acf.cash_flow_id" , self::$cash_flow_id_ATPay)
            ->first();

        if(!is_object($query) || Common::IsNullOrEmptyString($query->info))
        {
            return null;
        }

        $jsonSetting = json_decode($query->info , true);
        return $jsonSetting;

        // return 
        // [
        //     "vendor_id" => "10446",
        //     "api_domain_url" => "https://example.invalid/",
        //     "withdrawal_api_url" => "Payment_Dfpay_add.html",
        //     "api_secret_key" => "fi073jlzd524eqxt7wo5e2mp5shleul2",
        // ];
    }
    
}