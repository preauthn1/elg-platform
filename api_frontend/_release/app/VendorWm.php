<?php

namespace App;

use DB;
use APP\Helpers\Common;

class VendorWm extends Vendor
{
    private $server_name;
    private $game_url;
    private $licensee_token;
    private $currency;
    private $licensee_code;
    private $game_mode;
    private $game_url_mode;

    function __construct($info)
    {
        $this->name = 'WM';
        $this->vendor_id = 16;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->server_name = $info->SERVER_NAME;
        $this->game_url = isset($info->GAME_URL) ? $info->GAME_URL : $info->SERVER_NAME;
        $this->licensee_token = [REDACTED];
        $this->currency = $info->CURRENCY;

        $this->licensee_code = isset($info->LICENSEE_CODE) ? $info->LICENSEE_CODE : 'XSG';
        $this->game_mode = isset($info->GAME_MODE) ? $info->GAME_MODE : 'real';
        /**
         * 開啟遊戲連結方式
         * 1 (旧有方式): mode/Licensee_id/game_id/configuration_id
         * 2 (新的方式): Licensee_code/mode/game code
         */
        $this->game_url_mode = isset($info->GAME_URL_MODE) ? $info->GAME_URL_MODE : 1;
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
    public function getGameUrl($username, $game_type, $language, $info)
    {
        try {
            $url = 'platform/feed/wallet/auth/';
            $data = array();
            $data['id'] = $username;
            $result = $this->post($url, $data, false);
            if (is_array($result) && !isset($result['Message'])) {
                $game_type = explode("-", $game_type);
                $game_info = json_decode($info);

                $url = $this->game_url . 'games/';
                if ($this->game_url_mode == 2 && isset($game_info->Code)) {
                    // 新的方式 Licensee_code/mode/game code
                    $url .= $this->licensee_code . '/' . $this->game_mode . '/' . $game_info->Code . '/';
                } else {
                    // 旧有方式 mode/Licensee_id/game_id/configuration_id
                    $url .= $this->game_mode . '/' . $game_type['0'] . '/' . $game_type['1'] . '/' . $game_type['2'] . '/';
                }

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
        \Log::info('wm_request_' . $url, ["data" => $data]);
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
        if($result) {
            \Log::info('wm_response_' . $url, ['request' => $data, 'response' => $result]);
        } else {
            \Log::error('wm_response_' . $url, ['request' => $data, 'response' => $result]);
        }

        return json_decode($result, true);
    }
}