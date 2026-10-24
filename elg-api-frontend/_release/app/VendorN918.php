<?php

namespace App;

use App\Helpers\VendorSend;
use DB;
use App\Helpers\Common;

class VendorN918 extends Vendor
{
    private $api_url;
    private $agent_id;
    private $auth_code;
    private $secret_Key;
    private $api_password;
    protected $vendor_send;

    function __construct($info)
    {
        $this->name = 'N918';
        $this->vendor_id = 33;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();
        $this->api_url = $info->API_URL;
        $this->agent_id = $info->AGENT_ID;
        $this->auth_code = $info->AUTH_CODE;
        $this->secret_Key = [REDACTED];
        $this->api_password = [REDACTED];
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($playerId, $passwrod = '')
    {
        try {
            $data = array();
            $data['action'] = 'getUserInfo';
            $data['userName'] = $playerId;
            $data['time'] = date('U') . '000';
            $data['authcode'] = $this->auth_code;
            $data['sign'] = $this->sign($data['authcode'] . $this->agent_id . $data['time'] . $this->secret_Key);
            $result = json_decode($this->vendor_send->send('n918_getBalance', $this->api_url, 'GET', $data), true);
            if (is_array($result) && $result['success'] === true && isset($result['ScoreNum'])) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['ScoreNum']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => isset($result['msg']) ? $result['msg'] : __('error_message.9996'),
                    'data' => $result
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
            $data = array();
            $data['action'] = 'setServerScore';
            $data['orderid'] =  $billNo;
            $data['scoreNum'] = ($type == 1) ? $credit : -$credit;
            $data['userName'] = $username;
            $data['time'] = date('U') . '000';
            $data['authcode'] = $this->auth_code;
            $data['sign'] = $this->sign($data['authcode'] . $this->agent_id . $data['time'] . $this->secret_Key);

            $result = json_decode($this->vendor_send->send('n918_getBalance', $this->api_url, 'GET', $data), true);
            if (is_array($result) && $result['success'] === true && $result['code'] == 0) {
                return true;
            } else {
                return isset($result['msg']) ? $result['msg'] : __('error_message.9996');
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

    private function sign($string)
    {
        return  strtoupper(md5(strtolower($string)));
    }
}