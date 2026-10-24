<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorAllbet extends Vendor
{
    private $api_url;
    private $propery_id;
    private $des_key;
    private $md5_key;
    private $agent;
    private $vendor_send;

    public function __construct($info)
    {
        $this->vendor_id = 35;
        $this->name = 'Allbet';
        $this->vendor_send = new VendorSend();

        $this->api_url = $info->API_URL;
        $this->propery_id = $info->PROPERY_ID;
        $this->agent = $info->AGENT;
        $this->des_key = $info->DES_KEY;
        $this->md5_key = $info->MD5_KEY;

        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $password, $game_type, $language, $back_url = '')
    {
        try {
            $url = $this->api_url . '/forward_game';
            $action = 'Allbet_get_game_url';

            $param = array();
            $param['random'] = mt_rand();
            $param['client'] = $username;
            $param['password'] = $password;
            $param['language'] = $language;

            $data = $this->data($param);
            $sign = $this->sign($data);

            $postData = array();
            $postData['data'] = $data;
            $postData['sign'] = $sign;
            $postData['propertyId'] = $this->propery_id;

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $postData));
            if (is_object($response) && $response->error_code == 'OK') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->gameLoginUrl,
                    'url' => ['moblie' => $response->gameLoginUrl, 'pc' => $response->gameLoginUrl]
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
    public function getBalance($username, $password = '')
    {
        try {
            $url = $this->api_url . '/get_balance';
            $action = 'Allbet_Balance';

            $param = array();
            $param['random'] = mt_rand();
            $param['client'] = $username;
            $param['password'] = $password;

            $data = $this->data($param);
            $sign = $this->sign($data);

            $postData = array();
            $postData['data'] = $data;
            $postData['sign'] = $sign;
            $postData['propertyId'] = $this->propery_id;

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $postData));
            if (is_object($response) && $response->error_code == 'OK') {
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
            $url = $this->api_url . '/agent_client_transfer';
            $action = ($type == 1) ? 'Allbet_Deposit' : 'Allbet_Withdrawal';

            $param = array();
            $param['random'] = mt_rand();
            $param['agent'] = $this->agent;
            $param['sn'] = $this->propery_id . substr('0000000000000' . $billNo, -13);
            $param['client'] = $username;
            $param['operFlag'] = $type == 1 ? 1 : 0;
            $param['credit'] = $credit;

            $data = $this->data($param);
            $sign = $this->sign($data);

            $postData = array();
            $postData['data'] = $data;
            $postData['sign'] = $sign;
            $postData['propertyId'] = $this->propery_id;

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $postData));
            if (is_object($response) && $response->error_code == 'OK') {
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

    private function data($param)
    {
        $real_param = http_build_query($param);
        $key = base64_decode($this->des_key);
        $pad = 8 - (strlen($real_param) % 8);
        $string = $real_param . str_repeat(chr($pad), $pad);
        $data = openssl_encrypt(
            $string,
            'DES-EDE3-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_NO_PADDING,
            base64_decode("AAAAAAAAAAA=")
        );
        $data = base64_encode($data);
        return $data;
    }

    private function sign($data)
    {
        return base64_encode(md5($data . $this->md5_key, true));
    }
}