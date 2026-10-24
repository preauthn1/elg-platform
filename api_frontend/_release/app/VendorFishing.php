<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorFishing extends Vendor
{
    private $api_url;
    private $game_url;
    private $key;
    private $vendor_send;

    public function __construct($info)
    {
        $this->name = 'Fishing';
        $this->vendor_id = 39;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend(['json_post' => true]);
        $this->api_url = $info->API_URL;
        $this->game_url = $info->GAME_URL;
        $this->key = $info->KEY;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $token, $language)
    {
        try {
            $time = date('U');

            $data = array();
            $data['uid'] = $username;
            $data['ncode'] = $username;
            $data['token'] = $token;
            $data['timestamp'] = $time;
            $data['lang'] = $language;
            $data['sign'] = $this->sign($data);

            $gameUrl = $this->game_url . '?' . http_build_query($data);

            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $gameUrl,
                'url' => ['moblie' => $gameUrl, 'pc' => $gameUrl]
            ];
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
            $url = $this->api_url . '/3rd_querycoin.aspx?source=1056';
            $action = 'Fishing_Balance';
            $time = date('U');

            $data = array();
            $data['uuid'] = $username;
            $data['timestamp'] = $time;
            $data['sign'] = $this->sign($data);

            $header = array('Content-Type:application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $data, $header));

            if (is_object($response) && $response->code == 200) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->count
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->msg
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
            $url = $this->api_url . '/3rd_exchange.aspx?source=1056';
            $action = ($type == 1) ? 'Rm_Deposit' : 'Rm_Withdrawal';

            $data = array();
            $data['uuid'] = $username;
            $data['orderId'] = $billNo;
            $data['count'] = $credit;
            $data['type'] = $type;
            $data['sign'] = $this->sign($data);

            $header = array('Content-Type:application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $data, $header));
            if (is_object($response) && $response->code == 200) {
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
        $str = http_build_query($data) . '&key=' . $this->key;
        return md5($str);
    }
}