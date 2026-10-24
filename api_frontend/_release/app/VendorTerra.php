<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorTerra extends Vendor
{
    private $api_url;
    private $api_secret;

    public function __construct($info)
    {
        $this->name = 'Terra';
        $this->vendor_id = 34;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend(array('json_post' => true));

        $this->api_url = $info->API_URL;
        $this->api_secret = [REDACTED];
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $url = $this->api_url . '/api_Login';
            $action = 'Terra_get_gameUrl';

            $data = array();
            $data['memberId'] = $username;
            $data['gameId'] = $game_type;
            $data['lang'] = $language;

            $postData = array();
            $postData['Key'] = $this->get_key($data);
            $postData = array_merge($postData, $data);

            $gameUrl = $url . "?" . http_build_query($postData);

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
    public function getBalance($username, $password = '')
    {
        try {
            $url = $this->api_url . '/api_GetMemberCurrentInfo';
            $action = 'Terra_Balance';

            $data = array();
            $data['memberIds'] = $username;

            $postData = array();
            $postData['Key'] = $this->get_key($data);
            $postData = array_merge($postData, $data);

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $postData), true);
            if (is_array($response) && $response['response']['error'] == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response['data']['0']['coin']
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
            $url = $this->api_url . '/api_Transfer';
            $data = array();
            $data['memberId'] = $username;
            $data['transactionId'] = $billNo;
            $data['amount'] = $credit;
            if ($type == 1) {
                $action = 'Terra_Deposit';
                $data['transferType'] = 0;
            } else {
                $action = 'Terra_Withdrawal';
                $data['transferType'] = 1;
            }
            $postData = array();
            $postData['Key'] = $this->get_key($data);
            $postData = array_merge($postData, $data);
            $response = $this->vendor_send->send($action, $url, 'GET', $postData);
            $response = json_decode($response, true);
            if (is_array($response) && $response['response']['error'] == 0) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response->response->message;
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

    private function get_key($data)
    {
        return '00000' . md5(http_build_query($data) . '&Key=' . $this->api_secret) . '00000';
    }
}