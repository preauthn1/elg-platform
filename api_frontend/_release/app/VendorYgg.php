<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorYgg extends Vendor
{
    private $host;
    private $topOrg;
    private $org;
    private $currency;
    private $countryCode;
    private $key;

    function __construct($info)
    {
        $this->name = 'YGG';
        $this->vendor_id = 15;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->host = $info->HOST;
        $this->topOrg = $info->TOP_ORG;
        $this->org = $info->ORG;
        $this->currency = $info->CURRENCY;
        $this->countryCode = $info->COUNTRY_CODE;
        $this->key = $info->KEY;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = 'yggGame/getBalance';
            $data = array();
            $data['loginname'] = $username;
            $data['sign'] = $this->sign($username, $this->key);
            $result = $this->post($data, $url);
            if (is_array($result) && $result['code'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result : __('error_message.9996')
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
    public function transafer($username, $password = '', $type, $credit, $billNo)
    {
        try {
            if ($type == 1) {
                //存款
                $url = 'yggGame/credit';
            } else {
                //提款
                $url = 'yggGame/withdraw';
            }
            $data = array();
            $data['loginname'] = $username;
            $data['amount'] = $credit;
            $data['billno'] = $billNo;
            $data['sign'] = $this->sign($username, $this->key);
            $result = $this->post($data, $url);
            if (is_array($result) && $result['code'] === 0) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $result['message'];
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
     * -進遊戲
     */
    public function getGameUrl($username, $gameType, $language)
    {
        try {
            $url = 'yggGame/loginGame';
            $data = array();
            $data['loginname'] = $username;
            $data['gameId'] = $gameType;
            $data['language'] = $language;
            $data['channel'] = 'pc'; //pc的連結可以直接在手機使用，所以暫時不用取兩次
            $data['countryCode'] = $this->countryCode;
            $data['sign'] = $this->sign($username, $this->key);
            $result = $this->post($data, $url);
            if (is_array($result) && $result['code'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['data'],
                    'url' => ['mobile' => $result['data'], 'pc' =>  $result['data']]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result : __('error_message.9996')
                ];
            }
            // $pc = $this->getGameUrlByChannel($data, $url, 'pc');
            // if (is_array($pc) && $pc['code'] === 1000) {
            //     $mobile = $this->getGameUrlByChannel($data, $url, 'mobile');
            //     if (is_array($mobile) && $mobile['code'] === 1000) {
            //         return [
            //             'code' => 1000,
            //             'message' => __('error_message.1000'),
            //             'data' => $mobile['data'],
            //             'url' => ['mobile' => $mobile['data'], 'pc' =>  $pc['data']]
            //         ];
            //     } else {
            //         return [
            //             'code' => 9996,
            //             'message' => $mobile ? $mobile : __('error_message.9996')
            //         ];
            //     }
            // } else {
            //     return [
            //         'code' => 9996,
            //         'message' => $pc ? $pc : __('error_message.9996')
            //     ];
            // }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    // private function getGameUrlByChannel($data, $url, $channel)
    // {
    //     $data['channel'] = $channel;
    //     $result = $this->post($data, $url);
    //     if (is_array($result) && $result['code'] === 0) {
    //         return [
    //             'code' => 1000,
    //             'data' => $result['data'],
    //         ];
    //     } else {
    //         return [
    //             'code' => 9996,
    //             'message' => $result ? $result : __('error_message.9996')
    //         ];
    //     }
    // }

    private function sign($username, $key)
    {
        return md5($username . $key);
    }

    private function post($data, $url)
    {
        $data['topOrg'] = $this->topOrg;
        $data['org'] = $this->org;
        $data['currency'] = $this->currency;
        Common::log(0, $data, 'ygg_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,  $this->host . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS,  http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/x-www-form-urlencoded',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'ygg_' . $url);
        return json_decode($result, true);
    }
}