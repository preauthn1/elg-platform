<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorMenang88 extends Vendor
{
    private $api_url;
    private $pc_game_url;
    private $mobile_game_url;
    private $token;

    private $vendor_send;


    public function __construct($info)
    {
        $this->name = 'menang88';
        $this->vendor_id = 40;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();
        $this->api_url = $info->API_URL;
        $this->pc_game_url = $info->PC_GAME_URL;
        $this->mobile_game_url = $info->MOBILE_GAME_URL;
        $this->token = [REDACTED];
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($player_token)
    {
        try {

            $addToken = [REDACTED]' . $player_token;

            if (isset($player_token) && $player_token != '') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $this->mobile_game_url . $addToken,
                    'url' => ['moblie' => $this->mobile_game_url . $addToken, 'pc' => $this->pc_game_url . $addToken]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => 'Token_Error'
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
            $url = $this->api_url . 'GetAccountBalance.aspx';
            $action = 'Menang88_Balance';

            $data = array();
            $data['token'] = $this->token;
            $data['uid'] = $username;

            $url .= '?' . $this->params($data);

            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $data), true);

            if (is_array($response) && $response['status'] == '1') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response['creditbalance']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response['message']
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


            if ($type == 1) {
                $action = 'Menang88_Deposit';
                $url .= 'SetAccountDeposit.aspx';
            } else {
                $action = 'Menang88_Withdrawal';
                $url .= 'SetAccountWithdrawal.aspx';
            }

            $data = array();
            $data['token'] = $this->token;
            $data['uid'] = $username;
            $data['pay'] = $credit;

            $url .= '?' . $this->params($data);

            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $data), true);

            if (array($response) && $response['status'] == '1') {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response['message'];
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
}