<?php

namespace App;

use APP\Helpers\Common;

class VendorMt extends Vendor
{
    private $api_url;
    private $merchanId;
    private $md5_key;
    private $currency;

    public function __construct($info)
    {
        $this->name = 'MT';
        $this->vendor_id = 19;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->MT_API_URL;
        $this->merchanId = $info->MT_MERCHANID;
        $this->md5_key = $info->MT_KEY;
        $this->currency = $info->MT_CUR;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $password, $game_type, $language)
    {
        try {
            $url = $this->api_url . 'playerPlatformUrl/';
            $pwd = [REDACTED];
            $rawData = array();
            $rawData['gameCode'] = $game_type;
            $rawData['lang'] = $language;
            $rawData['roomID'] = '';
            $rawData = json_encode($rawData);
            $data = array();
            $data['merchanId'] = $this->merchanId;
            $data['username'] = $username;
            $data['pwd'] = $pwd;
            $data['rawData'] = $rawData;
            $url .= $this->merchanId . '/' . $username . '/' . $pwd . '/';
            $result = $this->post($url, $data, 1);
            if (is_array($result) && $result['resultCode'] === '1') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['url'],
                    'url' => ['moblie' => $result['url'], 'pc' => $result['url']]
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
            $url = $this->api_url . 'getPlayerBalance/';
            $rawData = array();
            $rawData['currency'] = $this->currency;
            $rawData = json_encode($rawData);
            $data = array();
            $data['username'] = $username;
            $data['merchanId'] = $this->merchanId;
            $data['rawData'] = $rawData;
            $url .= $username . '/' . $this->merchanId . '/';
            $result = $this->post($url, $data, 0);
            if (is_array($result) && $result['resultCode'] === '1') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['coinBalance'],
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
    public function transafer($username, $password, $type, $vendor_point, $bill_no)
    {
        try {
            if ($type == 1) {
                //存款
                $url = $this->api_url . 'deposit2/';
            } else {
                //提款
                $url = $this->api_url . 'withdraw2/';
            }
            //coins 需要到小數第4位，所以無條件捨去到小數點第4位
            $vendor_point = floor($vendor_point*10000)/10000;
            $vendor_point = number_format($vendor_point, 4, '.', '');
            //rawData
            $rawData = array();
            $rawData['merchantId'] = $this->merchanId;
            $rawData['playerName'] = $username;
            $rawData['extTransId'] = $bill_no;
            $rawData['coins'] = $vendor_point;
            $rawData['currency'] = $this->currency;
            $rawData = json_encode($rawData);
            $data = array();
            $data['merchanId'] = $this->merchanId;
            $data['username'] = $username;
            $data['coins'] = $vendor_point;
            $data['extTransId'] = $bill_no;
            $data['rawData'] = $rawData;
            $url .= $this->merchanId . '/' . $username . '/' . $vendor_point . '/' . $bill_no . '/';
            $result = $this->post($url, $data, 1);
            if (is_array($result) && $result['resultCode'] === '1') {
                //轉帳成功
                return true;
            } else {
                //轉帳失敗
                return [
                    'code' => 9996,
                    //'message' => $result ? $result : __('error_message.9996')
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

    private function post($url, $data, $type)
    {
        if ($type == 0) {
            $data['rawData'] = base64_encode($data['rawData']);
            $url .= $data['rawData'];
            $lasturl = $data['rawData'];
        } else {
            $code = md5($this->md5_key . $data['rawData']);
            $data['code'] = $code;
            $data['rawData'] = base64_encode($data['rawData']);
            $url .= $data['code'] . '/' . $data['rawData'];
            $lasturl = $data['code'] . '/' . $data['rawData'];
        }
        $data = json_encode($data);
        Common::log(0, $url . $lasturl , 'MT_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'MT_' . $url);
        curl_close($ch);
        return json_decode($result, true);
    }
}
