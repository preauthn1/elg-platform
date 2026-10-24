<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorObet33 extends Vendor
{
    private $api_url;
    private $agent_id;
    private $api_key;

    public function __construct($info)
    {
        $this->name = 'Obet33';
        $this->vendor_id = 43;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();

        $this->api_url = $info->API_URL;
        $this->agent_id = $info->AGENT_ID;
        $this->api_key = [REDACTED];
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $language = 'en')
    {
        try {
            $url = $this->api_url . '/api/sb/open';
            $action = 'Obet33_Game';

            $username = $this->agent_id . $username;
            $data = array(
                'hash' => $this->hash($username),
                'agentId' => $this->agent_id,
                'userId' => $username,
                'lang' => $language,
                'mobile' => 1
            );

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $data), true);

            if (is_array($response) && $response['Status'] == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response['Data']['LoginUrl']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response['message']
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2],
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * - 取帳號資料(Get User Detail)
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = $this->api_url . '/api/sb/bal';
            $action = 'Obet33_Balance';

            $username = $this->agent_id . $username;
            $data = array(
                'hash' => $this->hash($username),
                'agentId' => $this->agent_id,
                'userId' => $username
            );

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $data), true);

            if (is_array($response) && $response['Status'] == 'success') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response['Data']['Balance']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response['message']
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * -轉帳
     */
    public function transafer($username, $password, $type, $credit, $billNo)
    {
        try {
            $url = $this->api_url . '/api/sb/fund';
            $action = ($type == 1) ? 'Obet33_Deposit' : 'Obet33_Withdrawal';

            $username = $this->agent_id . $username;

            $data = array(
                'hash' => $this->hash($username),
                'agentId' => $this->agent_id,
                'userId' => $username,
                'amount' => ($type == 1) ? $credit : $credit * -1,
                'serialNo' => $billNo
            );

            //轉帳要用到josn格式的POST
            $post = new VendorSend(['json_post'=>true]);
            $header = array('Content-Type:application/json');

            $response = json_decode($post->send($action, $url, 'POST', $data, $header), true);

            if (is_array($response) && $response['Status'] == 'success') {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return [
                    'code' => 9996,
                    'message' => $response['Status']
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function hash($username)
    {
        $str = "k=$this->api_key&a=$this->agent_id&u=$username";
        //加密要回傳大寫
        return strtoupper(md5($str));
    }

}