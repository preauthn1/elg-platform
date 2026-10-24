<?php

namespace App;

use App\Helpers\Common;
use UUID;

class VendorLucky extends Vendor
{
    private $api_url;
    private $api_key;
    private $game_url;
    private $currency;
    private $channel;

    public function __construct($info)
    {
        $this->name = 'Lucky';
        $this->vendor_id = 12;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->game_url = $info->GAME_URL;
        $this->api_key = [REDACTED];
        $this->currency = $info->CURRENCY;
        $this->channel = $info->CHANNEL;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $data = array();
            $data['cmd'] = 'walletCheck';
            $data['channel'] = $this->channel;
            $data['key'] = $this->api_key;
            $data['uid'] = $username;
            $result = $this->post($data);
            if (is_array($result) && $result['code'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['balance']
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
     */
    public function transafer($username, $password = '', $type, $credit, $billNo)
    {
        try {
            $cmd = ($type == 1) ? 'walletIn' : 'walletOut';
            $data = array();
            $data['cmd'] = $cmd;
            $data['channel'] = $this->channel;
            $data['key'] = $this->api_key;
            $data['uid'] = $username;
            $data['amt'] = $credit;
            $data['tid'] = $billNo;
            $result = $this->post($data);
            if (is_array($result) && $result['code'] === 0) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $result['msg'];
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
     * -進遊戲
     */
    public function getGameUrl($username, $gameType, $language)
    {
        try {
            // New players
            $data = array();
            $data['cmd'] = 'login';
            $data['channel'] = $this->channel;
            $data['key'] = $this->api_key;
            $data['uid'] = $username;
            $result = $this->post($data);
            if (is_array($result) && $result['code'] === 0) {
                $timestamp = date('U');
                $url = $this->game_url . "game/?time=$timestamp&gameId=$gameType&language=$language&channel=" . $data['channel'] . "&uid=$username&session=" . $result['session'];
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $url,
                    'url' => ['mobile' => $url, 'pc' => $url]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result['Message']
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function post($data)
    {
        Common::log(0, $data,  'Lucky_' . $data['cmd']);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);

        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result],  'Lucky_' . $data['cmd']);
        return json_decode($result, true);
    }
}