<?php

namespace App;

use DB;
use APP\Helpers\Common;

class VendorVplay extends Vendor
{
    private $api_url;
    private $platform;
    private $version;
    private $username_suffix;
    private $currency;

    function __construct($info)
    {
        $this->name = 'VPlay';
        $this->vendor_id = 8;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->platform = $info->PLATFORM;
        $this->version = $info->VERSION;
        $this->username_suffix = $info->USERNAME_SUFFIX;
        $this->currency = $info->CURRENCY;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = 'MobileApp/GetBalance.ashx';
            $data = array();
            $data['Account'] = $username . $this->username_suffix;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['ResultCode'] == 1) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Point']
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

    /**
     * -轉帳
     */
    public function transafer($username, $password, $type, $credit, $billNo)
    {
        try {
            $url = 'MobileApp/TransferCredit.ashx';
            $data = array();
            $data['Account'] = $username . $this->username_suffix;
            $data['KeyInOutType'] = $type;
            $data['Credit'] = $credit;
            $data['CurrencyName'] = $this->currency;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['ResultCode'] == 1) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $result['ResultMsg'];
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

    private function post($url, $data)
    {
        Common::log(0, $data, 'VPLAY_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url . '?platform=' . $this->platform . '&timestamp=' . date('U') . '&ver=' . $this->version . '&language=zh_CN');
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'VPLAY_' . $url);
        curl_close($ch);
        return json_decode($result, true);
    }
}