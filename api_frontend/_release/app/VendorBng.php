<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;

class VendorBng extends Vendor
{
    private $api_url;
    private $api_token;
    private $project_name;
    private $wl;
    private $currency;
    private $vendor_send;

    public function __construct($info)
    {
        $this->vendor_id = 49;
        $this->name = 'Booongo';
        $this->vendor_send = new VendorSend(['json_post' => true]);

        $this->api_url = $info->API_URL;
        $this->api_token = [REDACTED];
        $this->project_name = $info->PROJECT_NAME;
        $this->wl = $info->WL;
        $this->currency = $info->CURRENCY;

        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $url_list = array();
            $url = $this->api_url . '/' . $this->project_name . '/game.html?';
            $time = time();
            $token = [REDACTED];

            $platform_list = ['mobile' => 'mobile', 'pc' => 'desktop'];

            foreach ($platform_list as $key => $row) {
                $data = array(
                    "token" => $token,
                    "game" => $game_type,
                    "ts" => $time,
                    "platform" => $row,
                    "wl" => $this->wl,
                    "lang" => $language
                );
                $url_list[$key] = $url . http_build_query($data);
            }


            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $url_list['mobile'],
                'url' => $url_list
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
            $url = $this->api_url . $this->project_name . '/wallet/' . $this->wl . '/get_player';
            $action = $this->name . '_Balance';

            $postData = array();
            $postData['api_token'] = $this->api_token;
            $postData['player_id'] = $username;
            $postData['currency'] = $this->currency;
            $postData['mode'] = "";
            $postData['brand'] = "";

            $headers = array('Content-Type:application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));

            if (is_object($response) && !isset($response->error)) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->balance
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
            $url = $this->api_url . $this->project_name . '/wallet/' . $this->wl . '/transfer_balance';
            $action = $this->name . ($type == 1) ? "_Deposit" : "_Withdrawal";

            $postData = array();
            $postData['api_token'] = $this->api_token;
            $postData['player_id'] = $username;
            $postData['brand'] = "";
            $postData['currency'] = $this->currency;
            $postData['mode'] = "";
            $postData['uid'] = $billNo;
            $postData['amount'] = strval($credit);
            $postData['type'] = ($type == 1) ? "CREDIT" : "DEBIT";

            $headers = array('Content-Type:application/json');
            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));
            if (is_object($response) && !isset($response->error)) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response->error;
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

    private function getToken($username)
    {
        $url = $this->api_url . $this->project_name . '/wallet/' . $this->wl . '/get_player_token';
        $action = $this->name . '_Game';

        $postData = array();
        $postData['api_token'] = $this->api_token;
        $postData['player_id'] = $username;
        $postData['currency'] = $this->currency;
        $postData['mode'] = "";
        $postData['brand'] = "";
        $postData['tag'] = "";

        $headers = array('Content-Type:application/json');
        $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));

        if (is_object($response) && isset($response->player_token)) {
            return $response->player_token;
        } else {
            return "";
        }
    }
}