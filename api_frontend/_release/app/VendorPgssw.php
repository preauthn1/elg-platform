<?php

namespace App;

use App\Helpers\VendorSend;
use App\Models\PgsswAccount;
use log;
use UUID;
use DB;

class VendorPgssw extends Vendor
{
    private $api_url;
    private $AppID;
    private $key;
    protected $vendorsend;

    public function __construct($info)
    {
        $this->name = 'PGSSW';
        $this->vendor_id = 50;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendorsend = new VendorSend();
        $this->api_url = $info->API_URL;
        $this->AppID = $info->API_APPID;
        $this->key = $info->API_KEY;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($account_id, $username, $game_type, $language)
    {
        try {
            //每次登入前更新session_token
            $account = PgsswAccount::find($account_id);
            $account->update(['session_token' => (string)UUID::generate()]);
            $session_token = [REDACTED];

            $url = $this->api_url . 'game/launch-link';
            $params = [
                'playerUniqueID' => $username,
                'sessionToken' => $session_token,
                'merchantID' => $this->AppID,
                'merchantSecretKey' => $this->key,
                'lang' => $language,
                'GameID' => $game_type
            ];
            $headers = array('Content-Type: application/json');
            $result = json_decode($this->vendorsend->send('pgsssw_getGameUrl', $url, 'GET', $params, $headers), true);
            if (is_array($result) && $result['code'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']['link'],
                    'url' => ['moblie' => $result['data']['link'], 'pc' => $result['data']['link']]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result,
                ];
            }
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
            $url = $this->api_url . 'v1/wallet/amount';
            $params = [
                'playerName' => $username,
                'merchantSecretKey' => $this->key,
                'merchantID' => $this->AppID,
            ];
            $headers = array('Content-Type: application/json');
            $result = json_decode($this->vendorsend->send('pgsssw_getBalance', $url, 'GET', $params, $headers), true);
            if (is_array($result) && $result['code'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']['amount'],
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result,
                ];
            }
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
            $url = $this->api_url . (($type == 1) ? 'v1/wallet/transfer-in' : 'v1/wallet/transfer-out');
            //產生訂單編號
            $random = '0';
            for ($i = 0; $i <= 2; $i++) {
                $random .= rand(0, 9);
            }
            $time = time();
            $RequestID = $username . $time . $random;
            $params = [
                'playerName' => $username,
                'merchantSecretKey' => $this->key,
                'merchantID' => $this->AppID,
                'amount' => $vendor_point,
                'merchantOrderNo' => $RequestID
            ];
            $headers = array('Content-Type: application/json');
            $result = json_decode($this->vendorsend->send('pgsssw_transafer', $url, 'POST', $params, $headers), true);
            if (is_array($result) && $result['code'] === 0) {
                //轉帳成功
                return true;
            } else {
                //轉帳失敗
                return [
                    'code' => 9996,
                    'message' => $result['message'],
                ];
            }
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }
}
