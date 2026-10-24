<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorIsb extends Vendor
{
    private $host;
    private $version;
    private $licensee;
    private $key;
    private $currency;
    private $operator;
    private $html5_url;

    public function __construct($info)
    {
        $this->name = 'ISB';
        $this->vendor_id = 14;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->host = $info->HOST;
        $this->version = $info->VERSION;
        $this->licensee = $info->LICENSEE_ID;
        $this->key =  $info->KEY;
        $this->currency = $info->CURRENCY;
        $this->operator = $info->OPERATOR;
        $this->html5_url = $info->HTML5_URL;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username, $playerId)
    {
        try {
            $data = array();
            $data['operator'] = $this->operator;
            $data['playerid'] = $playerId;
            $data['username'] = $username;
            $data['currency'] = $this->currency;
            $data['action'] = [
                'command' => 'getPlayerBalance',
            ];
            $result = $this->post($data);
            if (is_array($result) && $result['status'] == 'success') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => ($result['balance'] / 100) // 單位為分
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
    public function transafer($username, $playerId = '', $type, $credit, $billNo)
    {
        try {
            if ($type == 1) {
                //存款
                $command = 'depositFunds';
            } else {
                //提款
                $command = 'withdrawFunds';
            }

            $data = array();
            $data['operator'] = $this->operator;
            $data['playerid'] = $playerId;
            $data['username'] = $username;
            $data['currency'] = $this->currency;
            $data['action'] = [
                'command' => $command,
                'parameters' => [
                    'amount' => ($credit * 100), // 轉帳單位為分
                    'transactionid' => $billNo,
                ],
            ];
            $result = $this->post($data);
            if (is_array($result) && $result['status'] == 'success') {
                //轉點成功
                return true;
            } else {
                //轉點失敗
                return [
                    'code' => 9996,
                    'message' => $result['message'] ? $result : __('error_message.9996')
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
     * -進遊戲
     */
    public function getGameUrl($username, $playerId, $gameType, $language)
    {
        try {

            $data = array();
            $data['operator'] = $this->operator;
            $data['playerid'] = $playerId;
            $data['username'] = $username;
            $data['currency'] = $this->currency;
            $data['action'] = [
                'command' => 'getPlayerToken',
            ];
            $result = $this->post($data);
            if (is_array($result) && $result['status'] == 'success') {
                $token = [REDACTED]'token'];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result : __('error_message.9996')
                ];
            }

            $url = $this->html5_url . $this->licensee . '/' . $gameType . '?lang=' . $language . '&cur=' . $this->currency . '&operator=' . $this->operator . '&mode=1&user=' . $username . '&uid=' . $playerId . '&token=' . $token;
            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $url . '&allowFullScreen=false',
                'url' => ['mobile' => $url . '&allowFullScreen=false', 'pc' => $url]
            ];
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
        Common::log(0, $data, 'isb_' . $data['action']['command']);

        $hash = hash_hmac('SHA256', json_encode($data), $this->key);
        $url = $this->host . $this->version .  '/' . $this->licensee . '?hash=' . $hash;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,  $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json;charset=utf-8',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['url' => $url, 'request' => $data, 'response' => $result], 'isb_' . $data['action']['command']);
        return json_decode($result, true);
    }
}