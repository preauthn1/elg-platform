<?php

namespace App;

use App\Helpers\Common;

class VendorIa extends Vendor
{
    private $api_url;
    private $api_key;
    private $api_iv;
    private $pch;
    private $currency;

    public function __construct($info)
    {
        $this->name = 'IA';
        $this->vendor_id = 21;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->api_key = [REDACTED];
        $this->api_iv = $info->API_IV;
        $this->pch = $info->API_PCH;
        $this->currency = $info->CURRENCY;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $url = '/user/lunch';
            $data = array();
            $data['client'] = $game_type;
            $data['lang'] = $language;
            $data['username'] = $username;
            $md5_str = $this->getmd5str($data);
            $data['auth_key'] = $md5_str;
            $params = $this->encrypt($data);
            $result = $this->post($url, $params);
            if (is_object($result) && $result->status === 1) {
                Common::log(0, ['url' => $result->data->url], 'IA_gameurl');
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->data->url,
                    'url' => ['moblie' => $result->data->url, 'pc' => $result->data->url],
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
    public function getBalance($username)
    {
        try {
            $url = '/user/balance';
            $data = array();
            $data['username'] = $username;
            $md5_str = $this->getmd5str($data);
            $data['auth_key'] = $md5_str;
            $params = $this->encrypt($data);
            $result = $this->post($url, $params);
            if (is_object($result) && $result->status === 1) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->data,
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
     * -轉帳
     */
    public function transafer($username, $password = '', $type, $vendor_point, $bill_no)
    {
        try {
            if ($type == 1) {
                //存款
                $url = '/user/deposit';
            } else {
                //提款
                $url = '/user/withdraw';
            }
            $data = array();
            $data['amount'] = $vendor_point;
            $data['order_id'] = $bill_no;
            $data['username'] = $username;
            $md5_str = $this->getmd5str($data);
            $data['auth_key'] = $md5_str;
            $params = $this->encrypt($data);
            $result = $this->post($url, $params);
            if (is_object($result) && $result->status === 1) {
                //轉帳成功
                return true;
            } else {
                //轉帳失敗
                return [
                    'code' => 9996,
                    'message' => $result['Error'],
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
     * -把資料轉成API要的格式
     */
    private function getmd5str($data)
    {
        $str = '';
        $time = 0;
        foreach ($data as $key => $row) {
            if ($time == 0) {
                $str .= $key . '=' . $row;
            } else {
                $str .= ',' . $key . '=' . $row;
            }
            $time++;
        }
        $str = md5($str);
        return $str;
    }
    /**
     * -AES加密
     */
    private function encrypt($params)
    {
        $params = json_encode($params);
        $cryptText = openssl_encrypt($params, "aes-256-cbc", $this->api_key, OPENSSL_RAW_DATA, $this->api_iv);
        $data = base64_encode($cryptText);
        return $data;
    }
    /**
     * -AES解密
     */
    public function decrypt($params)
    {
        $params = base64_decode($params);
        $cryptText = openssl_decrypt($params, "aes-256-cbc", $this->api_key, OPENSSL_RAW_DATA, $this->api_iv);
        return json_decode($cryptText);
    }

    private function post($url, $data = array())
    {
        Common::log(0, $data, 'IA_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('pch:' . $this->pch));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'IA_' . $url);
        curl_close($ch);
        return $this->decrypt($result);
    }
}
