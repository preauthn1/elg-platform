<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use log;
use DB;

class VendorVsport extends Vendor
{
    private $api_url;
    private $agent;
    private $secret;
    private $acc_type;
    private $host;
    private $theme;
    private $vendor_send;

    public function __construct($info)
    {
        $this->vendor_id = 53;
        $this->name = 'VSport';
        $this->vendor_send = new VendorSend();

        $this->api_url = $info->API_URL;
        $this->agent = $info->AGENT;
        $this->secret = [REDACTED];
        $this->acc_type = $info->ACC_TYPE;
        $this->host = $info->HOST;
        $this->theme = $info->THEME;

        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $language)
    {
        try {
            $url = $this->api_url;
            $action = $this->name . '_Game';

            $data = array();
            $data['secret'] = $this->secret;
            $data['action'] = "login_mobile";
            $data['agent'] = $this->agent;
            $data['username'] = $username;
            $data['lang'] = $language;
            $data['accType'] = $this->acc_type;
            $data['host'] = $this->host;
            if (isset($this->theme) && $this->theme != '') {
                $data['theme'] = $this->theme;
            }


            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $data));
            if (is_object($response) && $response->errcode == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->result
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->errtext
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
            $action = $this->name . '_Balance';

            $data = array();
            $data['secret'] = $this->secret;
            $data['action'] = 'balance';
            $data['agent'] = $this->agent;
            $data['username'] = $username;

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $data));
            if (is_object($response) && $response->errcode == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->result
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->errtext
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
            $action = $this->name . ($type == 1) ? "_Deposit" : "_Withdrawal";

            $data = array();
            $data['secret'] = $this->secret;
            $data['action'] = ($type == 1) ? "deposit" : "withdraw";
            $data['agent'] = $this->agent;
            $data['username'] = $username;
            $data['serial'] = $billNo;
            $data['amount'] = $credit;

            $response = json_decode($this->vendor_send->send($action, $url, 'GET', $data));
            if (is_object($response) && $response->errcode == 0) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response->errtext;
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
}