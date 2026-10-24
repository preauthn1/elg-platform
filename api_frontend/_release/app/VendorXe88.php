<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorXe88 extends Vendor
{
    private $api_url;
    private $agent_id;
    private $signature_key;
    private $html5_base_url;

    public function __construct($info)
    {
        $this->name = 'XE88';
        $this->vendor_id = 11;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->agent_id = $info->AGENT_ID;
        $this->signature_key = $info->SIGNATURE_KEY;
        $this->html5_base_url = $info->HTML5_BASE_URL;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = 'player/info';
            // New players
            $data = array();
            $data['agentid'] = $this->agent_id;
            $data['account'] = $username;
            $result = $this->post($data, $url);
            if (is_array($result) && $result['code'] == '0') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['result']['balance']
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
            if ($type == 1) {
                //存款
                $url = 'player/deposit';
            } else {
                //提款
                $url = 'player/withdraw';
            }
            $data = array();
            $data['agentid'] = $this->agent_id;
            $data['account'] = $username;
            $data['amount'] = $credit;
            $data['trackingid'] = $billNo;
            $result = $this->post($data, $url);
            if (is_array($result) && $result['code'] == '0') {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $result['message'];
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
     * -修改密碼
     */
    public function resetPassword($username, $newPassword)
    {
        try {
            $url = 'player/update';
            // update players
            $data = array();
            $data['agentid'] = $this->agent_id;
            $data['account'] = $username;
            $data['password'] = 'Aa1' . $newPassword;
            $result = $this->post($data, $url);
            if (is_array($result) && $result['code'] == '0') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000')
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
     * -進遊戲
     */
    public function getGameUrl($username, $password, $gameType, $language)
    {
        try {
            $url = $this->html5_base_url . '?language='. $language .'&gameid=' . $gameType . '&userid=' . $username . '&userpwd=' . md5('Aa1' . $password);
            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $url,
                'url' => ['mobile' => $url, 'pc' => $url]
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

    private function post($data, $url)
    {
        $requestbody = json_encode($data);
        Common::log(0, $data, 'xe88_' . $url);
        $hashdata = hash_hmac("sha256", $requestbody, $this->signature_key, true);
        $hash = base64_encode($hashdata);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestbody);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'hashkey: ' . $hash,
        ));
        $result = curl_exec($ch);

        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'xe88_' . $url);
        return json_decode($result, true);
    }
}