<?php

namespace App;

use App\Helpers\VendorSend;
use log;

class VendorEsport extends Vendor
{
    private $api_url;
    private $platformId;
    private $key;
    private $iv;
    protected $vendor_send;

    public function __construct($info)
    {
        $this->name = 'Esport';
        $this->vendor_id = 38;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend(['json_post' => true]);
        $this->api_url = $info->API_URL;
        $this->platformId = $info->PLATFORMID;
        $this->key = $info->KEY;
        $this->iv = $info->IV;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language='')
    {
        try {
            $url = $this->api_url . '/user/login';
            $param = array(
                'platFormId' => $this->platformId,
                'userId' => $username
            );

            $postData = ['data' => $this->aes(json_encode($param))];

            $headers = array('Content-Type: application/json', 'device: pc');
            $response = $this->vendor_send->send('esport_getGameUrl', $url, 'POST', $postData, $headers);
            $result = json_decode($response, true);

            if (is_array($result) && $result['code'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data'],
                    'url' => ['moblie' => $result['data'], 'pc' => $result['data']]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result,
                ];
            }
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = $this->api_url . '/user/get_balance';
            $param = array(
                'platFormId' => $this->platformId,
                'userId' => $username
            );

            $postData = ['data' => $this->aes(json_encode($param))];

            $headers = array('Content-Type: application/json');
            $response = $this->vendor_send->send('esport_getBalance', $url, 'POST', $postData, $headers);
            $result = json_decode($response, true);

            if (is_array($result) && $result['code'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']['balance'],
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result,
                ];
            }
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * -轉帳
     */
    public function transafer($username, $password = '', $type, $vendor_point, $bill_no)
    {
        try {
            $url = $this->api_url . '/user/recharge';
            //amount不能使用字串
            $param = array(
                'platFormId' => $this->platformId,
                'tradeId' => $bill_no,
                'userId' => $username,
                'amount' => floatval($vendor_point),
                'operate' => $type == 1 ? 0 : 1
            );

            $postData = ['data' => $this->aes(json_encode($param))];

            $headers = array('Content-Type: application/json');
            $response = $this->vendor_send->send('esport_transafer', $url, 'POST', $postData, $headers);
            $result = json_decode($response, true);

            if (is_array($result) && $result['code'] === 0) {
                //轉帳成功
                return true;
            } else {
                //轉帳失敗
                return [
                    'code' => 9996,
                    'message' => $result['message'],
                ];
            }
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }

    private function aes($data)
    {
        $encrypter = openssl_encrypt($data, 'aes-256-cbc', $this->key, 1, $this->iv);
        return base64_encode($encrypter);
    }
}
