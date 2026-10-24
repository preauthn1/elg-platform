<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorV8 extends Vendor
{
    private $api_url;
    private $agent;
    private $des_key;
    private $md5_key;
    private $line_code;
    private $vendor_send;

    public function __construct($info)
    {
        $this->name = 'V8';
        $this->vendor_id = 42;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();

        $this->api_url = $info->API_URL;
        $this->agent = $info->AGENT;
        $this->des_key = $info->DESKEY;
        $this->md5_key = $info->MD5KEY;
        $this->line_code = $info->LINECODE;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language='')
    {
        try {
            $url = $this->api_url;
            $action = 'V8_Game';
            $time = time() . '000';

            $data = array(
                's' => 0,
                'account' => $username,
                'money' => 0,
                'orderid' => $this->agent . $time . $username,
                'ip' => '0.0.0.0',
                'lineCode' => $this->line_code,
                'KindID' => $game_type
            );

            $message = http_build_query($data);
            $param = $this->getParam($message);

            $postData = array(
                "agent" => $this->agent,
                "timestamp" => $time,
                "param" => $param,
                "key" => $this->getKey($time)
            );
            //不使用http_build_query()是因為param內有些字會被換掉
            $getParam = $this->params($postData);
            $url .= '?' . $getParam;
            $response = json_decode($this->vendor_send->send($action, $url, 'GET'), true);
           
            if (is_array($response) && $response['d']['code'] == 0) {
                $gameUrl = str_replace('lang=vi_vn','lang='.$language, $response['d']['url']);
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $gameUrl,
                    'url' => ['moblie' => $gameUrl, 'pc' => $gameUrl]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->response->message
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
            $url = $this->api_url;
            $action = 'V8_Balance';
            $time = time() . '000';

            $data = array(
                's' => 7,
                'account' => $username
            );

            $message = http_build_query($data);
            $param = $this->getParam($message);

            $postData = array(
                "agent" => $this->agent,
                "timestamp" => $time,
                "param" => $param,
                "key" => $this->getKey($time)
            );
            //不使用http_build_query()是因為param內有些字會被換掉
            $getParam = $this->params($postData);
            $url .= '?' . $getParam;
            $response = json_decode($this->vendor_send->send($action, $url, 'GET'), true);

            if (is_array($response) && $response['d']['code'] == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response['d']['freeMoney']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->response->message
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
            $url = $this->api_url;
            $time = time() . '000';
            if($type == 1){
                $action = 'V8_Deposit';
                $s = 2;
            } else {
                $action = 'V8_Withdrawal';
                $s = 3;
            }

            $data = array(
                's' => $s,
                'account' => $username,
                'money' => $credit,
                'orderid' => $this->agent . $time . $username
            );
            
            $message = http_build_query($data);
            $param = $this->getParam($message);

            $postData = array(
                "agent" => $this->agent,
                "timestamp" => $time,
                "param" => $param,
                "key" => $this->getKey($time)
            );
            //不使用http_build_query()是因為param內有些字會被換掉
            $getParam = $this->params($postData);
            $url .= '?' . $getParam;
            $response = json_decode($this->vendor_send->send($action, $url, 'GET'), true);

            if (is_array($response) && $response['d']['code'] == 0) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response['d']['code'];
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

    private function params($data)
    {
        $str = '';
        foreach ($data as $key => $val) {
            $str .= $key . '=' . $val;
            if ($val !== end($data)) {
                $str .= '&';
            }
        }
        return $str;
    }

    private function getParam($message)
    {
        return urlencode(openssl_encrypt($message, 'aes-128-ecb', $this->des_key, 0));
    }

    private function getKey($time)
    {
        return md5($this->agent . $time . $this->md5_key);
    }
}