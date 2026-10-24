<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorSkybet extends Vendor
{
    private $api_url;
    private $agent;
    private $login_url;
    private $key;

    public function __construct($info)
    {
        $this->name = 'SkyBet';
        $this->vendor_id = 31;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
        
        $this->vendor_send = new VendorSend(array('json_post' => true));

        $this->api_url = $info->API_URL;
        $this->agent = $info->AGENT;
        $this->login_url = $info->LOGIN_URL;
        $this->key = $info->DES_KEY;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $back_url = '')
    {
        try {
            $url = $this->api_url . '/WJMemLogin';
            $action = 'Skybet_get_token';

            $data = array();
            $data['UName'] = $username;
            $data['Timestamp'] = date('U') . '000';
            $data = $this->des_encrypt($data, $this->key);

            $postData = array();
            $postData['WJAgent'] = $this->agent;
            $postData['Data'] = $data;

            $headers = array('Content-Type: application/json');
            $token = [REDACTED], $url, 'POST', $postData, $headers));
            if (is_object($token) && $token->IsSuccess == true) {
                $gameUrl = $this->login_url . '?token=' . $token->data . '&LoginOut=' . $back_url;
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $gameUrl,
                    'url' => ['moblie' => $gameUrl, 'pc' => $gameUrl]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $token->message
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
            $url = $this->api_url . 'WJBalance';
            $action = 'Skybet_Balance';

            $data = array();
            $data['UName'] = $username;
            $data['Timestamp'] = date('U') . '000';
            $data = $this->des_encrypt($data, $this->key);

            $postData = array();
            $postData['WJAgent'] = $this->agent;
            $postData['Data'] = $data;

            $headers = array('Content-Type: application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));

            if (is_object($response) && $response->IsSuccess == true) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->data
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->message
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
            $url = $this->api_url . 'WJTransfer';
            $data = array();
            if ($type == 1) {
                $action = 'Skybet_Deposit';
                $data['Type'] = 0;
            } else {
                $action = 'Skybet_Withdrawal';
                $data['Type'] = 1;
            }
            $data['UName'] = $username;
            $data['Amount'] = $credit;
            $data['Timestamp'] = date('U') . '000';
            $data = $this->des_encrypt($data, $this->key);
            
            $postData = array();
            $postData['WJAgent'] = $this->agent;
            $postData['Data'] = $data;

            $headers = array('Content-Type: application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));
            if (is_object($response) && $response->IsSuccess == true) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response->message;
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

    private function des_encrypt($data, $key)
    {
        $key = md5($key);
        $key = substr($key, 0, 8);
        $data = json_encode($data);
        return bin2hex(openssl_encrypt($data, 'des-cbc', $key, 1, $key));
    }
}