<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use log;
use DB;

class VendorYbet extends Vendor
{
    private $api_url;
    private $app_id;
    private $api_key;
    private $vendor_send;

    public function __construct($info)
    {
        $this->vendor_id = 52;
        $this->name = 'Yeebet';
        $this->vendor_send = new VendorSend();

        $this->api_url = $info->API_URL;
        $this->app_id = $info->APP_ID;
        $this->api_key = [REDACTED];

        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $url = $this->api_url . '/login';
            $action = $this->name . '_Game';

            $data = array();
            $data['appid'] = $this->app_id;
            $data['username'] = $username;
            $data['iscreate'] = 0;
            // 1 : PC, 2 : 手機, 3 : ipad
            $data['clienttype'] = 2;
            $data['sign'] = $this->sign($data);

            $url .= '?' . $this->getParam($data);
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', []));
            if (is_object($response) && $response->result == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->openurl
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->error
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
            $url = $this->api_url . '/user/balance';
            $action = $this->name . '_Balance';

            $data = array();
            $data['appid'] = $this->app_id;
            $data['username'] = $username;
            $data['sign'] = $this->sign($data);

            $url .= '?' . $this->getParam($data);
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', []));
            if (is_object($response) && $response->result == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->balance
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->desc
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
            $url = $this->api_url . '/user/dw';
            $action = $this->name . ($type == 1) ? "_Deposit" : "_Withdrawal";

            $data = array();
            $data['appid'] = $this->app_id;
            $data['username'] = $username;
            $data['tradeno'] = $billNo;
            $data['amount'] = ($type == 1) ? $credit : -$credit;
            $data['sign'] = $this->sign($data);

            $url .= '?' . $this->getParam($data);

            $response = json_decode($this->vendor_send->send($action, $url, 'POST', []));
            if (is_object($response) && $response->result == 0) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response->message;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            log::error($this->name . '_exception', ['code' => $info[0], 'message' => $info[2]]);
            return '';
        } catch (\Exception $e) {
            log::error($this->name . '_exception', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
            return '';
        }
    }

    private function sign($param)
    {
        ksort($param);
        $msg = $this->getParam($param);
        $msg .= '&key=' . $this->api_key;
        return strtolower(md5($msg));
    }

    private function getParam($param)
    {
        $msg = '';
        foreach ($param as $key => $val) {
            $msg .= $key . '=' . $val;
            // $val 可能會有0 把它改成字串
            if ($val.'' != end($param)) {
                $msg .= '&';
            }
        }
        return $msg;
    }
}