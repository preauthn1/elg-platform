<?php

namespace App;

use DB;
use App\Helpers\Common;
use Log;

class VendorGreatWall extends Vendor
{
    private $api_url;
    private $api_merchant_name;
    private $api_agent;
    private $api_key;

    public function __construct($info)
    {
        $this->name = 'GreatWall';
        $this->vendor_id = 5;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->api_merchant_name = $info->API_MERCHANT_NAME;
        $this->api_agent = $info->API_AGENT;
        $this->api_key = [REDACTED];
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username, $passwrod = '')
    {
        try {

            $url = 'Api/IGame/Player/Info';
            $data = array();
            $data['PlayerAccount'] = $this->api_agent . $username;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['result']['code'] === '0') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']['balance']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result : __('error_message.9996'),
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
            $url = 'Api/IGame/Transaction/Create';
            // $billNo = date('His') . rand(10, 99);
            // 準備轉帳取轉帳編號
            $data = array();
            $data['PlayerAccount'] = $this->api_agent . $username;
            $data['Amount'] = ($type == 1) ? $credit : -$credit;
            $data['ExternalTransactionId'] = $billNo;
            ksort($data);
            $result = $this->post($url, $data);

            if (is_array($result) && $result['result']['code'] === '0') {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return  $result['result']['message'];
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
            $url = 'Api/IGame/Player/Resetpassword';
            $data = array();
            $data['PlayerAccount'] = $this->api_agent . $username;
            $data['Password'] = $newPassword;
            ksort($data);
            $result = $this->post($url, $data);
            if (is_array($result) && $result['result']['code'] === '0') {
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
     * -取APP下載連結
     */
    public function getAppUrl()
    {
        try {
            $url = 'Api/IGame/Appurl';
            $result = $this->post($url, []);
            if (is_array($result) && $result['result']['code'] === '0') {
                return [
                    'code' => 1000,
                    'data' => $result['data']
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
     * -取簽名
     */
    private function getSign($data)
    {
        if (count($data) > 0) {
            $string = http_build_query($data) . '&PrivateKey=' . $this->api_key;
        } else {
            $string = 'PrivateKey=' . $this->api_key;
        }
        $hash_hmac = hash('sha256', $string, true);
        return base64_encode($hash_hmac);
    }

    private function post($url, $data)
    {
        $uid = uniqid();
        Log::info('GreatWall_request_' . $uid . '_' . $url, $data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
        if (count($data) > 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'merchantName:' . $this->api_merchant_name,
            'signature:' . $this->getSign($data),
            'Content-Type:application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);
        if(!$result) {
            Log::error('GreatWall_response_' . $uid . '_' . $url, ['request' => $data, 'response' => 'Curl error: ' . curl_error($ch)]);
        } else {
            Log::info('GreatWall_response_' . $uid . '_' . $url, ['request' => $data, 'response' => $result]);
        }
        curl_close($ch);

        return json_decode($result, true);
    }
}