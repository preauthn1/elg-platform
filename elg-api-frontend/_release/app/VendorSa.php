<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use DB;
use Hash;

class VendorSa extends Vendor
{
    private $api_url;
    private $game_url;
    private $md5Key;
    private $secretKey;
    private $encryptKey;
    private $currency;
    private $lobby;
    private $vendor_send;


    public function __construct($info)
    {
        $this->name = 'sa';
        $this->vendor_id = 37;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();
        $this->api_url = $info->API_URL;
        $this->game_url = $info->GAME_URL;
        $this->md5Key = $info->MD5KEY;
        $this->secretKey = $info->SECRETKEY;
        $this->encryptKey = $info->ENCRYPTKEY;
        $this->currency = $info->CURRENCY;
        $this->lobby = $info->LOBBY;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language, $back_url = '')
    {
        try {
            $url = $this->api_url;
            $action = 'Sa_get_game_url';
            $time = date('YmdHis', time());

            $data = array();
            $data['method'] = 'LoginRequest';
            $data['Key'] = $this->secretKey;
            $data['Time'] = $time;
            $data['Username'] = $username;
            $data['CurrencyType'] = $this->currency;

            $Qs = $this->params($data);
            $s = md5($Qs . $this->md5Key . $time . $this->secretKey);
            $q = $this->sign($Qs);

            $postData = array(
                'q' => $q,
                's' => $s
            );

            $header = array('Content-Type:application/x-www-form-urlencoded');
            $response = $this->xmlToObject($this->vendor_send->send($action, $url, 'POST', $postData, $header));
            if (is_object($response) && $response->ErrorMsgId == '0') {
                $gameData = array(
                    'username' => $username,
                    'token' => $response->Token,
                    'lobby' => $this->lobby,
                    'lang' => $language,
                    'mobile' => true
                );
                $gameUrl = $this->game_url . '?' . $this->params($gameData);
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $gameUrl,
                    'url' => ['moblie' => $gameUrl, 'pc' => $gameUrl]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->msg
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
     * - 取帳號資料(Get User Detail)
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = $this->api_url;
            $action = 'Sa_Balance';
            $time = date('YmdHis', time());

            $data = array();
            $data['method'] = 'GetUserStatusDV';
            $data['Key'] = $this->secretKey;
            $data['Time'] = $time;
            $data['Username'] = $username;

            $Qs = $this->params($data);
            $s = md5($Qs . $this->md5Key . $time . $this->secretKey);
            $q = $this->sign($Qs);

            $postData = array(
                'q' => $q,
                's' => $s
            );

            $header = array('Content-Type:application/x-www-form-urlencoded');
            $response = $this->xmlToObject($this->vendor_send->send($action, $url, 'POST', $postData, $header));
            if (is_object($response) && $response->ErrorMsgId == '0') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response->Balance
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->msg
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
            $url = $this->api_url;
            $data = array();
            $time = date('YmdHis', time());

            if ($type == 1) {
                $action = 'Sa_Deposit';
                $data['method'] = 'CreditBalanceDV';
                $orderPrefix = 'IN';
                $amountKey = 'CreditAmount';
            } else {
                $action = 'Sa_Withdrawal';
                $data['method'] = 'DebitBalanceDV';
                $orderPrefix = 'OUT';
                $amountKey = 'DebitAmount';
            }

            $data['Key'] = $this->secretKey;
            $data['Time'] = $time;
            $data['Username'] = $username;
            $data['OrderId'] = $orderPrefix . $time . $username;
            $data[$amountKey] = $credit;

            $Qs = $this->params($data);
            $s = md5($Qs . $this->md5Key . $time . $this->secretKey);
            $q = $this->sign($Qs);

            $postData = array(
                'q' => $q,
                's' => $s
            );

            $header = array('Content-Type:application/x-www-form-urlencoded');
            $response = $this->xmlToObject($this->vendor_send->send($action, $url, 'POST', $postData, $header));

            if (is_object($response) && $response->ErrorMsgId == '0') {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response->error_code;
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

    private function params($data)
    {
        $str = '';

        foreach ($data as $key => $val) {
            $str .= $key . '=' . $val;
            if ($val !== end($data)) {
                $str .= '&';
            }
        }

        return $str;
    }

    private function sign($Qs)
    {
        return base64_encode(openssl_encrypt($Qs, 'DES-CBC', $this->encryptKey, OPENSSL_RAW_DATA, $this->encryptKey));
    }

    private function xmlToObject($result)
    {
        $xml = simplexml_load_string($result);
        $json = json_encode($xml);
        return json_decode($json);
    }
}