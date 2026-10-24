<?php

namespace App\Services;

use App\DES;

class AgService
{
    private $business_host;
    private $forward_host;
    private $md5_key;
    private $agent;
    private $currency;
    private $game_type;
    private $our_site = '';
    private $des = '';


    function __construct()
    {
        $this->currency = env('AG_CURRENCY');
        $this->game_type = env('AG_GAME_TYPE');
        $this->business_host = env('AG_BUSINESS_HOST');
        $this->forward_host = env('AG_FORWARD_HOST');
        $this->agent = env('AG_AGENT');
        $this->md5_key = env('AG_MD5_KEY');
        $this->des = new DES(env('AG_DES_KEY'), 'DES-ECB', DES::OUTPUT_BASE64);
    }

    private function request($url, $params = null, $needAgent = true)
    {
        $param = implode('/\\\\/', $params);
        $param = $this->des->encrypt($param);
        $key = md5($param . $this->md5_key);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?params=' . $param . '&key=' . $key);
        if ($needAgent) {
            curl_setopt($ch, CURLOPT_USERAGENT, 'WEB_LIB_GI_' . $this->agent);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    //確認或建立帳號
    function checkOrCreateAccount($username, $password, $oddType = 'A')
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=lg';//確認或建立帳號的編號
            $params[] = 'actype=' . $this->game_type;//0:試玩，1:真錢
            $params[] = 'password=' . $password;
            $params[] = 'oddtype=' . $oddType;//設定可下注的金額A、B、C、D...I
            $params[] = 'cur=' . $this->currency;//幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    //查詢帳號餘額
    function getBalance($username, $password)
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=gb';//查詢帳號餘額的編號
            $params[] = 'actype=' . $this->game_type;//0:試玩，1:真錢
            $params[] = 'password=' . $password;
            $params[] = 'cur=' . $this->currency;//幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    //預備轉帳
    function prepareTransfer($username, $password, $billNo, $type, $credit)
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=tc';//預備轉帳的編號
            $params[] = 'billno=' . $this->agent . $billNo;//轉帳編號8位數字內
            $params[] = 'type=' . $type;//IN(網站帳號轉到遊戲帳號) or OUT(遊戲帳號轉到網站帳號)
            $params[] = 'credit=' . $credit;//金額需為整數，保留到小數二位
            $params[] = 'actype=' . $this->game_type;//0:試玩，1:真錢
            $params[] = 'password=' . $password;
            $params[] = 'cur=' . $this->currency;//幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    //確認轉帳
    function confirmTransfer($username, $password, $billNo, $type, $credit)
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=tcc';//確認轉帳的編號
            $params[] = 'billno=' . $this->agent . $billNo;//轉帳編號8位數字內
            $params[] = 'type=' . $type;//IN(網站帳號轉到遊戲帳號) or OUT(遊戲帳號轉到網站帳號)
            $params[] = 'credit=' . $credit;//金額需為整數，保留到小數二位
            $params[] = 'actype=' . $this->game_type;//0:試玩，1:真錢
            $params[] = 'flag=1';//0:預備轉帳失敗，1:預備轉帳成功
            $params[] = 'password=' . $password;
            $params[] = 'cur=' . $this->currency;//幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    //查詢轉帳狀態
    function transferStatus($billNo)
    {
        try {
            $url = $this->business_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'method=qos';//查詢轉帳狀態的編號
            $params[] = 'billno=' . $this->agent . $billNo;//轉帳編號8位數字內
            $params[] = 'actype=' . $this->game_type;//0:試玩，1:真錢
            $params[] = 'cur=' . $this->currency;//幣種
            $result = $this->request($url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    //跳轉遊戲
    function forwardGame($username, $password, $sid, $gameType = 0, $oddType = 'A', $mh5 = null, $flashId = null, $sessionToken = [REDACTED], $lang = 3)
    {
        try {
            $url = $this->forward_host;
            $params = array();
            $params[] = 'cagent=' . $this->agent;
            $params[] = 'loginname=' . $username;
            $params[] = 'password=' . $password;
            $params[] = 'dm=' . $this->our_site;
            $params[] = 'sid=' . $this->agent . $sid;//13~16位的數字
            $params[] = 'lang=' . $lang;//語系
            $params[] = 'gameType=' . $gameType;//遊戲編號
            $params[] = 'oddtype=' . $oddType;//設定可下注的金額A、B、C、D...I
            $params[] = 'cur=' . $this->currency;
            if ($mh5) {
                $params[] = 'mh5=' . $mh5;
            }
            if ($flashId) {
                $params[] = 'flashid=' . $flashId;
            }
            if ($sessionToken) {
                $params[] = 'session_token=' . $sessionToken;
            }
            $param = implode('/\\\\/', $params);
            $param = $this->des->encrypt($param);
            $key = md5($param . $this->md5_key);
            return $url . '?params=' . $param . '&key=' . $key;
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

    function api_log($ip, $logData)
    {
        $filename = './AgLog/ag_api_log' . date("Ym") . '.txt';
        $fp = fopen($filename, 'a+');
        $datetime = date("Y-m-d H:i:s");
        $data = $datetime . " " . $ip . " [" . $logData . "]\r\n";
        fwrite($fp, $data);
        fclose($fp);
    }
}
