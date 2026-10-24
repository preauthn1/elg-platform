<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorPlus100 extends Vendor
{
    private $api_url;
    private $merch;
    private $api_key;
    private $vendor_send;

    public function __construct($info)
    {
        $this->vendor_id = 46;
        $this->name = '100Plus';
        $this->vendor_send = new VendorSend(['json_post' => true]);

        $this->api_url = $info->API_URL;
        $this->merch = $info->MERCH;
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
            $url = $this->api_url . '/m/enterGame';
            $action = '100Plus_Game';

            $postData = array();
            $postData['merch'] = $this->merch;
            $postData['gameId'] = $game_type;
            $postData['playerId'] = $username;
            $postData['lang'] = $language;

            $hash = $this->hash($postData);
            $url .= '?hash=' . $hash;

            $headers = array('Content-Type:application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));
            if (is_object($response) && $response->status == 'success') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->url,
                    'url' => ['moblie' => $response->url, 'pc' => $response->url]
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
            $url = $this->api_url . '/m/playerBalance';
            $action = '100Plus_Balance';

            $postData = array();
            $postData['merch'] = $this->merch;
            $postData['playerId'] = $username;


            $hash = $this->hash($postData);
            $url .= '?hash=' . $hash;

            $headers = array('Content-Type:application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));
            if (is_object($response) && $response->status == 'success') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->balance
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
            $url = $this->api_url;
            if($type == 1){
                $url .= '/m/deposit';
                $action = "100Plus_Deposit";
            } else {
                $url .= '/m/withdraw';
                $action = "100Plus_Withdrawal";
            }

            $postData = array();
            $postData['merch'] = $this->merch;
            $postData['playerId'] = $username;
            $postData['transactionId'] = $billNo;
            if($type == 2) {
                //轉出時是否全部取出 0 : 依照amount取出  1 : 全部取出
                $postData['takeAll'] = "0";
            }
            $postData['amount'] = $credit;

            $hash = $this->hash($postData);
            $url .= '?hash=' . $hash;

            $headers = array('Content-Type:application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));
            if (is_object($response) && $response->status == 'success') {
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


    private function hash($message)
    {
        $json_str = json_encode($message, JSON_UNESCAPED_UNICODE);
        return hash_hmac("SHA256", utf8_encode($json_str), $this->api_key);
    }
}