<?php

namespace App;

use DB;
use APP\Helpers\Common;

class VendorWm extends Vendor
{
    private $server_name;
    private $licensee_token;
    private $currency;

    function __construct($info)
    {
        $this->name = 'WM';
        $this->vendor_id = 16;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->server_name = $info->SERVER_NAME;
        $this->licensee_token = [REDACTED];
        $this->currency = $info->CURRENCY;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = 'platform/feed/wallet/balance/';
            $data = array();
            $data['id'] = $username;
            $result = $this->post($url, $data, false);
            if (is_array($result)) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['Balance']
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
    public function transafer($username, $password, $type, $vendor_point, $bill_no)
    {
        try {
            if ($type == 1) {
                //存款
                $url = 'platform/feed/wallet/Credit/';
            } else {
                //提款
                $url = 'platform/feed/wallet/Debit/';
            }
            $data = array();
            $data['Reference'] = $bill_no;
            $data['UserID'] = $username;
            $data['Amount'] = $vendor_point;
            $data['Currency'] = $this->currency;
            $result = $this->post($url, json_encode($data));
            if (is_array($result) && !isset($result['Error'])) {
                //轉帳成功
                return true;
            } else {
                //轉帳失敗
                return $result['Error'] ? $result['Description'] : __('error_message.9996');
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
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $url = 'platform/feed/wallet/auth/';
            $data = array();
            $data['id'] = $username;
            $result = $this->post($url, $data, false);
            if (is_array($result) && !isset($result['Message'])) {
                $game_type = explode("-", $game_type);
                $url = $this->server_name . 'games/real/' . $game_type['0'] . '/' . $game_type['1'] . '/' . $game_type['2'] . '/';
                $url .= '?authuser=' . $result['AuthUser'];
                $url .= '&authkey=' . $result['AuthKey'];
                $url .= '&authskin=' . $result['AuthSkin'];
                $url .= '&language=' . $language;
                $url .= '&display=iframe'; //- iframe：用于在浮动框架中开启游戏。启动器的输出是一个完整的页面，游戏界面适合浮动框架的大小。
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $url,
                    'url' => ['mobile' => $url, 'pc' => $url]
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

    private function post($url, $data, $isPost = true)
    {
        Common::log(0, $data, 'wm_' . $url);
        $param = '';
        $ch = curl_init();
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_POST, TRUE);
        } else {
            $param .= '?';
            foreach ($data as $key => $value) {
                $param .= $key . '=' . $value;
            }
        }
        curl_setopt($ch, CURLOPT_URL, $this->server_name . $url . $this->licensee_token . $param);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result],  'wm_' . $url);
        return json_decode($result, true);
    }
}