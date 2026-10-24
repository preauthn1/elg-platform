<?php

namespace App;

use App\Helpers\Common;
use DB;

class VendorPlay8oy extends Vendor
{
    private $api_url;
    private $api_merchant_name;
    private $api_agent;
    private $api_key;

    public function __construct($info)
    {
        $this->name = 'Play8oy';
        $this->vendor_id = 30;
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
    public function getBalance($username)
    {
        try {
            $url = 'player/info';
            $data = array();
            $data['PlayerAccount'] = $username;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['Code'] == '0') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Data']['Balance']
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
            $url = 'transaction/create';
            // 準備轉帳取轉帳編號
            $data = array();
            $data['PlayerAccount'] = $username;
            $data['Amount'] = ($type == 1) ? $credit : -$credit;
            $data['ExternalTransactionId'] = $billNo;
            ksort($data);
            $result = $this->post($url, $data);

            if (is_array($result) && $result['Code'] == '0') {
                // 轉帳單號
                $trans_id = $result['Data']['ApiTransId'];
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return  $result['Message'];
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
            $url = 'player/resetpassword';
            $data = array();
            $data['PlayerAccount'] = $username;
            $data['Password'] = $newPassword;
            ksort($data);
            $result = $this->post($url, $data);
            if (is_array($result) && $result['Code'] == '0') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result['Message'] : __('error_message.9996')
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
            $url = 'appurl';
            $result = $this->post($url, []);
            if (is_array($result) && $result['Code'] == '0') {
                return [
                    'code' => 1000,
                    'data' => $result['Data']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result['Message'] : __('error_message.9996')
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
        Common::log(0, $data, 'Play8oy_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
        if (count($data) > 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'merchantName:' . $this->api_merchant_name,
                'Sign:' . $this->getSign($data),
                'Content-Type:application/json'
            )
        );
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'Play8oy_' . $url);
        return json_decode($result, true);
    }
}