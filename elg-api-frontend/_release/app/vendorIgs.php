<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use log;

class VendorIgs extends Vendor
{
    private $api_url;
    private $app_id;
    private $app_key;
    private $secret;
    protected $vendorsend;

    public function __construct($info)
    {
        $this->name = 'IGS';
        $this->vendor_id = 26;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendorsend = new VendorSend();
        $this->api_url = $info->API_URL;
        $this->app_id = $info->APP_ID;
        $this->app_key = $info->APP_KEY;
        $this->secret = [REDACTED];
    }

    /**
     * -取帳號資料
     */
    public function getBalance($player_id)
    {
        try {
            $url = $this->api_url . 'openapi/site/user-info';
            $time = time();
            $sign = md5(md5('app_id'.$this->app_id . 'ts' . $time . 'user_id' . $player_id . $this->app_id));

            $data = array(
                'user_id' => $player_id,
                'ts' => time(),
                'app_id' => $this->app_id,
                'sign' => $sign
            );

            $headers = array('Authorization:' . $this->getToken());
            $result = json_decode($this->vendorsend->send('igs_getBalance', $url, 'GET', $data, $headers), true);
            if (is_array($result) && $result['msg'] == 'success') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']['available_amount'],
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
    public function transafer($player_id, $password= '', $type, $vendor_point, $bill_no)
    {
        try {
            $url = $this->api_url;
            $time = time();
            $sign = md5(md5('amount' . $vendor_point . 'app_id' . $this->app_id . 'ts' . $time . 'user_id' . $player_id . $this->app_id));
            if ($type == 1) {
                //存款
                $url .= 'openapi/site/transfer-amount';
            } else {
                //提款
                $url .= 'openapi/site/withdraw-amount';
            }

            $data = array(
                'user_id' => $player_id,
                'amount' => $vendor_point,
                'ts' => $time,
                'app_id' => $this->app_id,
                'sign' => $sign
            );

            $headers = array('Authorization:' . $this->getToken());
            $result = json_decode($this->vendorsend->send('igs_transafer', $url, 'POST', $data, $headers), true);
            if (is_array($result) && $result['msg'] == 'success') {
                //轉帳成功
                return true;
            } else {
                //轉帳失敗
                return [
                    'code' => 9996,
                    //'message' => $result ? $result : __('error_message.9996')
                    'message' => $result['msg'],
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
     * -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $url = $this->api_url . 'openapi/site/get-authed-url';
            $time = time();
            $sign = md5(md5('app_id' . $this->app_id . 'language' . $language . 'mode' . $game_type . 'ts' . $time . 'username' . $username . $this->app_id));
            $data = array(
                'username' => $username,
                'mode' => $game_type,
                'language' => $language,
                'ts' => $time,
                'app_id' => $this->app_id,
                'sign' => $sign
            );

            $headers = array('Authorization:' . $this->getToken());
            $result = json_decode($this->vendorsend->send('igs_get_gameurl', $url, 'POST', $data, $headers), true);

            if (is_array($result) && $result['msg'] == "success") {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']['url'],
                    'url' => ['moblie' => $result['data']['url'], 'pc' => $result['data']['url']],
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
     * -取Token
     */
    private function getToken()
    {
        $url = $this->api_url . 'openapi/oauth/access-token';
        $time = time();
        $sign = md5(md5('app_id' . $this->app_id . 'app_key' . $this->app_key . 'ts' . $time . $this->app_id));

        $data = array(
            'app_id' => $this->app_id,
            'app_key' => $this->app_key,
            'ts' => $time,
            'sign' => $sign,
        );
        $result = json_decode($this->vendorsend->send('igs_get_token', $url, 'POST', $data), true);
        return $result['data']['access_token'];
    }
}
