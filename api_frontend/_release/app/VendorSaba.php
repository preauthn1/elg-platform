<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorSaba extends Vendor
{
    private $api_url;
    private $game_url;
    private $api_vendor_id;
    private $wallet_id;
    private $currency;
    private $vendor_send;
    private $operator_id;
    private $headers;

    public function __construct($info)
    {
        $this->name = 'Saba';
        $this->vendor_id = 44;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();
        $this->headers = array('Content-Type: application/x-www-form-urlencoded');

        $this->api_url = $info->API_URL;
        $this->game_url = $info->GAME_URL;
        $this->api_vendor_id = $info->VENDOR_ID;
        $this->operator_id = $info->OPERATOR_ID;
        $this->wallet_id = $info->WALLET_ID;
        $this->currency = $info->CURRENCY;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $language = 'en', $is_mobile = true)
    {
        try {
            $url = $this->api_url . '/api/GetSabaUrl';
            $action = 'Saba_Game';
            $platform = ($is_mobile == true) ? 2 : 1;
            $data = array(
                'vendor_id' => $this->api_vendor_id,
                'platform' => $platform,
                'vendor_member_id' => $username,
            );

            $response = $this->vendor_send->send($action, $url, 'POST', $data, $this->headers);
            $result = json_decode($response, true);


            if (is_array($result) && $result['error_code'] == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Data'] . '&lang=' .  $language
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
            $url = $this->api_url . '/api/CheckUserBalance';
            $action = 'Saba_Balance';

            $data = array(
                'vendor_id' => $this->api_vendor_id,
                'vendor_member_ids' => $username,
                'wallet_id' => $this->wallet_id
            );

            $response = $this->vendor_send->send($action, $url, 'POST', $data, $this->headers);
            $result = json_decode($response, true);

            if (is_array($result) && $result['error_code'] == 0) {
                //如果錢包回0,API會回傳空值
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Data'][0]['balance'] == null ? 0 : $result['Data'][0]['balance']
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

            $url = $this->api_url . '/api/FundTransfer';
            $action = ($type == 1) ? 'Saba_Deposit' : 'Saba_Withdrawal';
            //issues_892 Saba轉帳金額不可以小於1
            if ($credit < 1) {
                return [
                    'code' => 1435,
                    'message' => __('error_message.1435')
                ];
            }

            $data = array(
                'vendor_id' => $this->api_vendor_id,
                'vendor_member_id' => $username,
                'vendor_trans_id' => $this->operator_id . '_' . $billNo,
                'amount' => $credit,
                'currency' => $this->currency,
                'direction' => ($type == 1) ? 1 : 0,
                'wallet_id' => $this->wallet_id
            );
            $response = $this->vendor_send->send($action, $url, 'POST', $data, $this->headers);
            $result = json_decode($response, true);
            if (is_array($result) && $result['error_code'] == 0) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response['Message'];
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

}