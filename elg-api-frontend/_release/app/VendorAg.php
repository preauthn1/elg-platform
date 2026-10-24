<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorAg extends Vendor
{
    private $business_host;
    private $forward_host;
    private $md5_key;
    private $agent;
    private $currency;
    private $game_type;
    private $odd_type;
    private $our_site = '';
    private $des = '';

    public function __construct($info)
    {
        $this->name = 'AG';
        $this->vendor_id = 1;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->currency = $info->CURRENCY;
        $this->game_type = $info->GAME_TYPE;
        $this->odd_type = isset($info->ODD_TYPE) ? $info->ODD_TYPE : '';
        $this->business_host = $info->BUSINESS_HOST;
        $this->forward_host = $info->FORWARD_HOST;
        $this->agent = $info->AGENT;
        $this->md5_key = $info->MD5_KEY;
        $this->des = new DES($info->DES_KEY, 'DES-ECB', DES::OUTPUT_BASE64);
    }

    private function request($url, $params = null, $needAgent = true)
    {
        $param = implode('/\\\\/', $params);
        Common::log(0, $param, 'AG_post');
        $param = $this->des->encrypt($param);
        $key = md5($param . $this->md5_key);
        $url = $url . '?params=' . $param . '&key=' . $key;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($needAgent) {
            curl_setopt($ch, CURLOPT_USERAGENT, 'WEB_LIB_GI_' . $this->agent);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request_url' => $url, 'request' => $params, 'response' => $result], 'AG_post');
        return $result;
    }

    // 確認或建立帳號
    function checkOrCreateAccount($username, $password, $oddType = 'A')
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=lg'; // 確認或建立帳號的編號
            $params[] = 'actype=' . $this->game_type; // 0:試玩，1:真錢
            $params[] = 'password=' . $password;
            $params[] = 'oddtype=' . $oddType; // 設定可下注的金額A、B、C、D...I
            $params[] = 'cur=' . $this->currency; // 幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    // 查詢帳號餘額
    function getBalance($username, $password)
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=gb'; // 查詢帳號餘額的編號
            $params[] = 'actype=' . $this->game_type; // 0:試玩，1:真錢
            $params[] = 'password=' . $password;
            $params[] = 'cur=' . $this->currency; // 幣種
            $result = $this->request($url, $params);

            if ($result != false) {
                $rs = simplexml_load_string($result);
                if (is_numeric((string) $rs->attributes()->info)) {
                    $log_data = array(
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => (string) $rs->attributes()->info
                    );
                } else {
                    $log_data = array(
                        'code' => 9996,
                        'message' => $rs['msg']
                    );
                }
            } else {
                $log_data = array(
                    'code' => 9995,
                    'message' => __('error_message.9995')
                );
            }

            return $log_data;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    // 轉帳
    function transafer($username, $password, $type, $credit, $billNo)
    {
        $type = ($type == 1) ? 'IN' : 'OUT';
        // $billNo = date('His') . rand(10, 99);
        // 先預備轉帳
        $result = $this->prepareTransfer($username, $password, $billNo, $type, $credit);
        if ($result == false) {
            return false;
        }
        if ((string) $result->attributes()->info === '0') {
            // 預備轉帳成功後執行確認轉帳
            $result = $this->confirmTransfer($username, $password, $billNo, $type, $credit);
            if ((string) $result->attributes()->info === '0') {
                return true;
            } else {
                return  (string) $result->attributes()->msg;
            }
        } else {
            return $result->attributes()->msg;
        }
    }

    // 預備轉帳
    function prepareTransfer($username, $password, $billNo, $type, $credit)
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=tc'; // 預備轉帳的編號
            $params[] = 'billno=' . $this->agent . $billNo; // 轉帳編號8位數字內
            $params[] = 'type=' . $type; // IN(網站帳號轉到遊戲帳號) or OUT(遊戲帳號轉到網站帳號)
            $params[] = 'credit=' . $credit; // 金額需為整數，保留到小數二位
            $params[] = 'actype=' . $this->game_type; // 0:試玩，1:真錢
            $params[] = 'password=' . $password;
            $params[] = 'cur=' . $this->currency; // 幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    // 確認轉帳
    function confirmTransfer($username, $password, $billNo, $type, $credit)
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=tcc'; // 確認轉帳的編號
            $params[] = 'billno=' . $this->agent . $billNo; // 轉帳編號8位數字內
            $params[] = 'type=' . $type; // IN(網站帳號轉到遊戲帳號) or OUT(遊戲帳號轉到網站帳號)
            $params[] = 'credit=' . $credit; // 金額需為整數，保留到小數二位
            $params[] = 'actype=' . $this->game_type; // 0:試玩，1:真錢
            $params[] = 'flag=1'; // 0:預備轉帳失敗，1:預備轉帳成功
            $params[] = 'password=' . $password;
            $params[] = 'cur=' . $this->currency; // 幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    // 查詢轉帳狀態
    function transferStatus($billNo)
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'method=qos'; // 查詢轉帳狀態的編號
            $params[] = 'billno=' . $this->agent . $billNo; // 轉帳編號8位數字內
            $params[] = 'actype=' . $this->game_type; // 0:試玩，1:真錢
            $params[] = 'cur=' . $this->currency; // 幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    // 跳轉遊戲
    function forwardGame($username, $password, $sid, $gameType = 0, $lang = 3, $oddType = 'A', $mh5 = 'y', $flashId = null, $sessionToken = [REDACTED]
    {
        try {
            $url = $this->forward_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'password=' . $password;
            $params[] = 'dm=' . $this->our_site;
            $params[] = 'sid=' . $this->agent . $sid; // 13~16位的數字
            $params[] = 'lang=' . $lang; // 語系
            $params[] = 'actype=' . $this->game_type; // 0:試玩，1:真錢
            $params[] = 'gameType=' . $gameType; // 遊戲編號
            $params[] = 'oddtype=' . ($this->odd_type != '' ? $this->odd_type : $oddType); // 設定可下注的金額A、B、C、D...I
            $params[] = 'cur=' . $this->currency;
            if ($flashId) {
                $params[] = 'flashid=' . $flashId;
            }
            if ($sessionToken) {
                $params[] = 'session_token=' . $sessionToken;
            }
            Common::log(0, $params, 'AG_post');
            $param = implode('/\\\\/', $params);
            $param = $this->des->encrypt($param);
            $key = md5($param . $this->md5_key);
            $pc = $url . '?params=' . $param . '&key=' . $key;

            $params[] = 'mh5=' . $mh5;
            $param = implode('/\\\\/', $params);
            $param = $this->des->encrypt($param);
            $key = md5($param . $this->md5_key);
            $mobile = $url . '?params=' . $param . '&key=' . $key;
            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' =>  $mobile,
                'url' => ['mobile' => $mobile, 'pc' => $pc]
            ];
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    function goToGame($feature, $id, $type, $stamp)
    {
        if ($feature == md5($id . $type . $stamp . $this->md5_key)) {
            header("Location:http://" . $this->our_site . "/xxxx.jsp");
        } else {
            return "error, invalid invoking api";
        }
    }
}