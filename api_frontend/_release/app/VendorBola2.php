<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorBola2 extends Vendor
{
    private $api_url;
    private $agent_name;
    private $api_key;
    private $vendor_send;
    private $headers;

    public function __construct($info)
    {
        $this->name = 'Saba';
        $this->vendor_id = 45;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend(['json_post' => true]);
        $this->headers = array('Content-Type: application/json');

        $this->api_url = $info->API_URL;
        $this->agent_name = $info->AGENT_NAME;
        $this->api_key = [REDACTED];
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $password, $language = 'E')
    {
        try {
            $url = $this->api_url . '/user/login';
            $action = 'Bola2_Game';

            $random = str_pad(mt_rand(0, 999999), 6, "0", STR_PAD_BOTH);

            $data = array(
                'AgentName' => $this->agent_name,
                'Random' => $random,
                'HashKey' => $this->hash($this->agent_name . $random . $this->api_key),
                'Username' => $username,
                'Password' => $password
            );

            $response = $this->vendor_send->send($action, $url, 'POST', $data, $this->headers);
            $result = json_decode($response, true);
            //回傳的只有字串,檢查是不是網址,不是就是錯誤訊息
            if (is_array($result) && $result['Status'] == 200) {

                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Message'] . '&lang=' . $language
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response
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
            $url = $this->api_url . '/user/getbalance/' . $username;
            $action = 'Bola2_Balance';

            $random = str_pad(mt_rand(0, 999999), 6, "0", STR_PAD_BOTH);

            $data = array(
                'AgentName' => $this->agent_name,
                'Random' => $random,
                'HashKey' => $this->hash($this->agent_name . $random . $this->api_key)
            );

            $response = $this->vendor_send->send($action, $url, 'POST', $data, $this->headers);
            $result = json_decode($response, true);
            if (is_array($result) && $result['Status'] == 200) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Message']
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
            $url = $this->api_url . '/transfer/';
            if ($type == 1) {
                $action = 'Bola2_Deposit';
                $url .= 'deposit/';
            } else {
                $action = 'Bola2_Withdrawal';
                $url .= 'withdraw/';
            }
            $url .= $username;

            $random = str_pad(mt_rand(0, 999999), 6, "0", STR_PAD_BOTH);

            $data = array(
                'AgentName' => $this->agent_name,
                'Random' => $random,
                'HashKey' => $this->hash($this->agent_name . $random . $this->api_key),
                'Amount' => $credit,
                'Remark' => ''
            );

            $response = $this->vendor_send->send($action, $url, 'POST', $data, $this->headers);
            $result = json_decode($response, true);

            if (is_array($result) && $result['Status'] == 201) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response['Message'];
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

    private function hash($str)
    {
        return md5($str);
    }

}