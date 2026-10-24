<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorSbo extends Vendor
{
    private $api_url;
    private $operator_token;
    private $secret_key;
    private $currency;

    function __construct($info)
    {
        $this->name = 'SBO';
        $this->vendor_id = 18;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->operator_token = [REDACTED];
        $this->secret_key = [REDACTED];
        $this->currency = $info->CURRENCY;
    }

    /**
     * -啟動遊戲
     * }
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $url = 'Launch';
            // 取遊戲URL
            $data = array();
            $data['player_name'] = $username;
            $data['game_code'] = $game_type;
            $data['nickname'] = $username;
            $data['language'] = $language;
            $result = $this->post($data, $url);
            if (is_array($result) && $result['error'] == NULL) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']['game_url']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result : __('error_message.9996')
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
     * -查詢錢包餘額
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = 'GetPlayerBalance';
            $data = array();
            $data['player_name'] = $username;
            $data['wallet_code'] = 'gf_sport_wallet';
            $result = $this->post($data, $url);
            if (is_array($result) && $result['error'] == null) {
                //結果成功回傳餘額
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']['balance']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result : __('error_message.9996')
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
     * }
     */
    public function transafer($username, $password, $type, $vendor_point, $bill_no)
    {
        try {
            if ($type == 1) {
                //存款
                $url = 'TransferIn';
            } else {
                //提款
                $url = 'TransferOut';
            }
            $data = array();
            $data['player_name'] = $username;
            $data['amount'] = $vendor_point;
            $data['traceId'] = $bill_no;
            $data['wallet_code'] = 'gf_sport_wallet';
            $result = $this->post($data, $url);
            if (is_array($result) && $result['error'] == NULL) {
                //轉帳成功
                return true;
            } else {
                //轉帳失敗
                return $result ? $result : __('error_message.9996');
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

    private function post($data, $url)
    {
        $data['secret_key'] = $this->secret_key;
        $data['operator_token'] = $this->operator_token;
        Common::log(0, $data, 'SBO_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/x-www-form-urlencoded',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'SBO_' . $url);
        return json_decode($result, true);
    }
}