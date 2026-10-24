<?php

namespace App;
use App\Helpers\Common;

class VendorTestgame extends Vendor
{
    private $api_url;
    private $game_url;
    private $channel;
    private $key;
    private $rate;

    public function __construct($info)
    {
        $this->name = 'TestGame';
        $this->vendor_id = 99;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->game_url = $info->API_GAMEURL;
        $this->channel = $info->API_CHANNEL;
        $this->key = $info->API_KEY;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($player_id)
    {
        try {
            $data = array();
            $data['cmd'] = 'login';
            $data['channel'] = $this->channel;
            $data['key'] = $this->key;
            $data['uid'] = intval($player_id);
            $result = $this->post($data);
            if (is_array($result) && $result['msg'] == 'success') {
                $session = $result['session'];
                $uid = $result['uid'];
                $gameurl = $this->game_url . "?session=$session&uid=$uid&channel=$this->channel";
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $gameurl,
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result,
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
     * -取帳號資料
     * }
     */
    public function getBalance($player_id)
    {
        try {
            $data = array();
            $data['cmd'] = 'walletCheck';
            $data['channel'] = $this->channel;
            $data['key'] = $this->key;
            $data['uid'] = intval($player_id);
            $result = $this->post($data);
            if (is_array($result) && $result['msg'] == 'success') {
                return [
                    'code' => 1000,
                    // 'message' => __('error_message.1000'),
                    'data' => $result['balance'],
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result['message'],
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
     * -轉帳0
     */
    public function transafer($player_id, $password, $type, $vendor_point, $bill_no)
    {
        try {
            if ($type == 1) {
                //存款
                $cmd = 'walletIn';
            } else {
                //提款
                $cmd = 'walletOut';
            }
            $data = array();
            $data['cmd'] = $cmd;
            $data['channel'] = $this->channel;
            $data['key'] = $this->key;
            $data['uid'] = intval($player_id);
            $data['amt'] = $vendor_point;
            $data['tid'] = $bill_no;
            $result = $this->post($data);
            if (is_array($result) && $result['msg'] == 'success') {
                    return true;
            } else {
                //轉帳失敗
                return [
                    'code' => 9996,
                    'message' => $result['msg'],
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

    private function post($data)
    {
        $data = json_encode($data);
        Common::log(0, $data, 'Testgame_' . $this->api_url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'Testgame_' . $this->api_url);
        curl_close($ch);
        return json_decode($result, true);
    }
}
