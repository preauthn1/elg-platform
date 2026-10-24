<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorScr extends Vendor
{
    private $host;
    private $agentName;
    private $userAreaId;
    private $authCode;
    private $secretKey;
    private $api_user;
    private $api_password;
    private $api_url;

    public function __construct($info)
    {
        $this->name = 'SCR';
        $this->vendor_id = 3;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = isset($info->transfer_min_amount) ? isset($info->transfer_min_amount) : 0.01;

        $this->api_url = $info->API_URL;
        $this->host = $info->HOST;
        $this->agentName = $info->AGENT_NAME;
        $this->userAreaId = $info->USER_AREA_ID;
        $this->authCode = $info->AUTH_CODE;
        $this->secretKey = $info->SECRET_KEY;
        $this->api_user=$info->API_USER;
        $this->api_password = [REDACTED];
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($playerId, $passwrod = '')
    {
        try {
            $url = 'account/account.ashx';
            $data = array();
            $data['action'] = 'getUserInfo';
            $data['userName'] = $playerId;
            $data['time'] = date('U') . '000';
            $data['authcode'] = $this->authCode;
            $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $this->secretKey);

            $result = $this->post($url, $data);

            if (is_array($result) && $result['success'] === true && isset($result['MoneyNum'])) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['MoneyNum']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => isset($result['msg']) ? $result['msg'] : __('error_message.9996')
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

            $url = 'account/setScore.ashx';
            $data = array();
            $data['action'] = 'setServerScore';
            $data['orderid'] =  $billNo;
            $data['scoreNum'] = ($type == 1) ? $credit : -$credit;
            $data['userName'] = $username;
            $data['ActionUser'] = $username;
            $data['ActionIp'] = Common::getIp();
            $data['time'] = date('U') . '000';
            $data['authcode'] = $this->authCode;
            $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $this->secretKey);

            $result =  $this->post($url, $data);
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
    private function post($url, $data)
    {
        Common::log(0, $data, 'SCR_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->host . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0,  ['request' => $data, 'response' => $result], 'SCR_' . $url);
        return json_decode($result, true);
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalanceOld($playerId, $passwrod = '')
    {
        try {

            $url = 'player';
            $data = array();
            $data['operation'] = 'getplayerinfo';
            $data['playerid'] = $playerId;
            $data['apiuserid'] = $this->api_user;
            $data['apipassword'] = $this->api_password;

            Common::log(0, $data, 'SCR_' . $url);
            $data_string = json_encode($data);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string)
            ));
            $result = curl_exec($ch);
            Common::log(0,  ['request' => $data, 'response' => $result], 'SCR_' . $url);
            curl_close($ch);

            $result = json_decode($result, true);
            if (is_array($result) && $result['returncode'] == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['balance']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result
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
}