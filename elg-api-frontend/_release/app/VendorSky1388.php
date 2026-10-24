<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorSky1388 extends Vendor
{
    private $api_url;
    private $agid;
	private $private_key;

    public function __construct($info)
    {
        $this->name = 'Sky1388';
        $this->vendor_id = 28;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();

        $this->api_url = $info->API_URL;
        $this->agid = $info->ID;
        $this->private_key = $info->PRIVATE_KEY;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language, $back_url = '')
    {
        try {
            $url = $this->api_url . '/user_play_game';
            $action = 'Sky1388_get_game_url';

            $data = array();
            $data['agid'] = $this->agid;
            $data['username'] = $username;
            $data['game_code'] = $game_type;
            $data['game_support'] = 'H5';
            $data['lang'] = $language;
            $data['game_back_url'] = $back_url;

            $sign = $this->sign($data);
            $data['signature'] = $sign;

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $data));
            if (is_object($response) && strtolower($response->status) == 'ok') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->url,
                    'url' => ['moblie' => $response->url, 'pc' => $response->url]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->error_code
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
            $url = $this->api_url . '/user_detail';
            $action = 'Sky1388_Balance';

            $data = array();
            $data['agid'] = $this->agid;
            $data['username'] = $username;

            $sign = $this->sign($data);
            $data['signature'] = $sign;

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $data));
            if (is_object($response) && strtolower($response->status) == 'ok') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->balance
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->error_code
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
            $url = $this->api_url . '/user_transfer';
            $action = ($type == 1) ? 'Sky1388_Deposit' : 'Sky1388_Withdrawal';

            $data = array();
            $data['agid'] = $this->agid;
            $data['amount'] = ($type == 1) ? $credit : strval($credit * -1);      //decimal; 1000.00
            $data['username'] = $username;
            $data['orderid'] = $billNo;
            $data['rolling'] = '';          //已不使用
            $data['wallet_type'] = '';      //已不使用

            $sign = $this->sign($data);
            $data['signature'] = $sign;

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $data));
            if (is_object($response) && strtolower($response->status) == 'ok') {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response->error_code;
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

    private function sign($data)
    {
        ksort($data);
        $str = implode('', $data) . $this->private_key;
        $sign = sha1($str);
        return $sign;
    }
}