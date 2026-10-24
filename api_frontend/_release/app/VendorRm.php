<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;
use log;

class VendorRm extends Vendor
{
    private $api_url;
    private $api_id;
    private $api_key;

    public function __construct($info)
    {
        $this->name = 'RM';
        $this->vendor_id = 32;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();
        $this->api_url = $info->API_URL;
        $this->api_id = $info->API_ID;
        $this->api_key = [REDACTED];
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language, $back_url = '')
    {
        try {
            $url = $this->api_url . 'api/acc/gameLogIn';
            $action = 'Rm_get_game_url';
            $time = date('U');

            $data = array();
            $data['api_id'] = $this->api_id;
            $data['timestamp'] = $time;
            $data['sign'] = $this->sign($time);
            $data['user_id'] = $username;
            $data['game_code'] = $game_type;
            $data['language'] = $language;
            $data['return_url'] = '';

            $header = array('Content-Type:application/x-www-form-urlencoded');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $data, $header));
            if (is_object($response) && $response->code == '0') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->gameUrl,
                    'url' => ['moblie' => $response->gameUrl, 'pc' => $response->gameUrl]
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
            $url = $this->api_url . 'api/acc/getBalance';
            $action = 'Rm_Balance';
            $time = date('U');

            $data = array();
            $data['api_id'] = $this->api_id;
            $data['timestamp'] = $time;
            $data['sign'] = $this->sign($time);
            $data['user_id'] = $username;

            $header = array('Content-Type:application/x-www-form-urlencoded');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $data, $header));
            if (is_object($response) && $response->code == '0') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->balance
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
            $url = $this->api_url . 'api/acc/updateBalance';
            $action = ($type == 1) ? 'Rm_Deposit' : 'Rm_Withdrawal';
            $time = date('U');

            $data = array();
            $data['api_id'] = $this->api_id;
            $data['timestamp'] = $time;
            $data['sign'] = $this->sign($time);
            $data['user_id'] = $username;
            $data['amount'] = $credit;
            $data['action'] = ($type == 1) ? 'deposit' : 'withdraw';
            $data['reference_id'] = $billNo;

            $header = array('Content-Type:application/x-www-form-urlencoded');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $data, $header));
            if (is_object($response) && $response->code == '0') {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗,紀錄log後回傳空值
                $context = [
                    'request' => [
                        'url' => $url,
                        'parameters' => $data,
                    ],
                    'response' => (array) $response
                ];
                log::error('Rm_transfer_fail', $context);
                return '';
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            log::error('Rm_exception', ['code' => $info[0], 'message' => $info[2]]);
            return '';
        } catch (\Exception $e) {
            log::error('Rm_exception', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
            return '';
        }
    }

    private function sign($time)
    {
        return md5(($this->api_id.$time.$this->api_key));
    }
}