<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;

class VendorNs extends Vendor
{
    private $api_url;
    private $game_url;
    private $merchant_code;
    private $currency;
    private $vendor_send;

    public function __construct($info)
    {
        $this->vendor_id = 48;
        $this->name = 'NextSpin';
        $this->vendor_send = new VendorSend(['json_post'=>true]);

        $this->api_url = $info->API_URL;
        $this->game_url = $info->GAME_URL;
        $this->merchant_code = $info->Merchant_Code;
        $this->currency = $info->CURRENCY;

        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $token, $language)
    {
        try {
            $url = $this->game_url . "?acctId=$username&language=$language&token=[REDACTED]";
            $action = 'NextSpin_Game';

            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $url,
                'url' => ['moblie' => $url, 'pc' => $url]
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
            $url = $this->api_url;
            $action = 'NextSpin_Balance';

            $postData = array();
            $postData['acctId'] = $username;
            $postData['pageIndex'] = 1;
            $postData['merchantCode'] = $this->merchant_code;
            $postData['serialNo'] = time() . rand(10, 99);

            $headers = array(
                'API: getAcctInfo',
                'DataType: JSON',
                'Content-Type:application/json'
            );

            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));
            if (is_object($response) && $response->msg == 'Success') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->list[0]->balance
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
            $url = $this->api_url;
            $headers = array();
            if ($type == 1) {
                $headers[] = 'API: deposit';
                $action = "NextSpin_Deposit";
            } else {
                $headers[] = 'API: withdraw';
                $action = "NextSpin_Withdrawal";
            }

            $postData = array();
            $postData['acctId'] = $username;
            $postData['currency'] = $this->currency;
            $postData['amount'] = $credit;
            $postData['serialNo'] = time() . rand(10, 99);
            $postData['merchantCode'] = $this->merchant_code;

            $headers[] = 'DataType: JSON';
            $headers[] = 'Content-Type:application/json';

            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData, $headers));
            if (is_object($response) && $response->msg == 'Success') {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response->msg;
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