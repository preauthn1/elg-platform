<?php

namespace App;
use APP\Helpers\Common;

class VendorVpower extends Vendor
{
    private $api_url;
    private $AppID;
    private $key;

    public function __construct($info)
    {
        $this->name = 'VPower';
        $this->vendor_id = 22;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->game_url = $info->API_GAMEURL;
        $this->AppID = $info->API_APPID;
        $this->key = $info->API_KEY;
    }

    /**
     * -取APP下載連結
     */
    public function getAppUrl($username)
    {
        try {
            $time = time();
            $AppID = $this->AppID;
            $Req = "Act=tkreq&Timestamp=$time&Username=$username&AppId=$AppID";
            $Signature = rawurlencode($this->hmac_sha1($Req, $this->key));
            $url = $this->api_url . "?" . $Req . "&Signature=" . $Signature;

            $data = array();
            $data['Act'] = 'tkreq';
            $data['Timestamp'] = $time;
            $data['Username'] = $username;
            $data['AppId'] = $AppID;
            $data['Signature'] = $Signature;
            $result = $this->get($url, $data);
            if (is_array($result) && $result['ErrorCode'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Token']
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
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $time = time();
            $AppID = $this->AppID;
            $Req = "Act=tkreq&Timestamp=$time&Username=$username&AppId=$AppID";
            $Signature = rawurlencode($this->hmac_sha1($Req, $this->key));
            $url = $this->api_url . "?" . $Req . "&Signature=" . $Signature;
            $back_url = base64_encode('example.invalid/GameList/22');

            $data = array();
            $data['Act'] = 'tkreq';
            $data['Timestamp'] = $time;
            $data['Username'] = $username;
            $data['AppId'] = $AppID;
            $data['Signature'] = $Signature;
            $result = $this->get($url, $data);
            if (is_array($result) && $result['ErrorCode'] === 0) {
                $gameUrl = $this->game_url . "?id=" . $game_type . '&token=' . $result['Token'].'&back='.$back_url;
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $gameUrl,
                    'url' => ['moblie' => $gameUrl, 'pc' => $gameUrl]
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
            $time = time();
            $AppID = $this->AppID;
            $Req = "Act=getbal&Timestamp=$time&Username=$username&AppId=$AppID";
            $Signature = rawurlencode($this->hmac_sha1($Req, $this->key));
            $url = $this->api_url . "?" . $Req . "&Signature=" . $Signature;

            //記錄LOG
            $data = array();
            $data['Act'] = 'getbal';
            $data['Timestamp'] = $time;
            $data['Username'] = $username;
            $data['AppId'] = $AppID;
            $data['Signature'] = $Signature;

            $result = $this->get($url, $data);
            if (is_array($result) && $result['ErrorCode'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Credit'],
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
    public function transafer($username, $password='', $type, $vendor_point, $bill_no)
    {
        try {
            if ($type == 1) {
                //存款
                $amount = $vendor_point;
            } else {
                //提款
                $amount = 0 - $vendor_point;
            }
            //產生訂單編號
            $random = '0';
            for ($i = 0; $i <= 2; $i++) {
                $random .= rand(0, 9);
            }

            $time = time();
            $RequestID = $username . $time . $random;
            $AppID = $this->AppID;
            $Req = "Act=creditxf&RequestID=$RequestID&Timestamp=$time&Username=$username&AppId=$AppID&Amount=$amount";
            $Signature = rawurlencode($this->hmac_sha1($Req, $this->key));
            $url = $this->api_url . "?" . $Req . "&Signature=" . $Signature;

            //記錄LOG
            $data = array();
            $data['Act'] = 'creditxf';
            $data['RequestID'] = $RequestID;
            $data['Timestamp'] = $time;
            $data['Username'] = $username;
            $data['AppId'] = $AppID;
            $data['Signature'] = $Signature;

            $result = $this->get($url, $data);
            if (is_array($result) && $result['ErrorCode'] === 0) {
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
     * 加密
     */
    private function hmac_sha1($str, $key)
    {
        $signature = "";
        if (function_exists('hash_hmac')) {
            $signature = base64_encode(hash_hmac("sha1", $str, $key, true));
        } else {
            $blocksize = 64;
            $hashfunc = 'sha1';
            if (strlen($key) > $blocksize) {
                $key = pack('H*', $hashfunc($key));
            }
            $key = str_pad($key, $blocksize, chr(0x00));
            $ipad = str_repeat(chr(0x36), $blocksize);
            $opad = str_repeat(chr(0x5c), $blocksize);
            $hmac = pack('H*', $hashfunc(($key ^ $opad) . pack('H*', $hashfunc(($key ^ $ipad) . $str))));
            $signature = base64_encode($hmac);
        }
        return $signature;
    }

    private function get($url, $data)
    {
        $data = json_encode($data);
        Common::log(0, $data, 'Vpower_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'Vpower_' . $url);
        curl_close($ch);
        return json_decode($result, true);
    }

}
