<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use Log;

class VendorPussy888 extends Vendor
{
    private $api_url;
    private $authcode;
    private $secretKey;
    protected $vendorsend;

    public function __construct($info)
    {
        $this->name = 'Pussy888';
        $this->vendor_id = 23;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendorsend = new VendorSend();
        $this->api_url = $info->API_URL1;
        $this->authcode = $info->API_AUTHCODE;
        $this->secretKey = $info->API_SECRETKEY;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = $this->api_url;
            $url .= 'account/account.ashx';
            $time = time() . '000';
            $authcode = $this->authcode;
            $secretKey = $this->secretKey;
            $sign = $this->getsign($authcode, $username, $time, $secretKey);

            $data = array(
                'action' => 'getUserInfo',
                'userName' => $username,
                'time' => $time,
                'authcode' => $authcode,
                'sign' => $sign,
            );

            $result = json_decode($this->vendorsend->send('pussy888_getBalance', $url, 'GET', $data), true);
            if (is_array($result) && $result['success'] == true) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['MoneyNum'],
                ];
            } else {
                Log::info(sprintf('bbbbbbbbbbbbbbbbbbbbb %d %d', is_array($result), ($result['success'] == true)), ["result"=>$result]);
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
    public function transafer($username, $password = '', $type, $vendor_point, $bill_no)
    {
        // dd();
        try {
            $url = $this->api_url;
            $url .= 'account/setScore.ashx';
            $time = time() . '000';
            $authcode = $this->authcode;
            $secretKey = $this->secretKey;
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

            $sign = $this->getsign($authcode, $username, $time, $secretKey);

            $data = array(
                'action' => 'setServerScore',
                'scoreNum' => $amount,
                'userName' => $username,
                'ActionUser' => 'vworldmy',
                'ActionIp' => '0.0.0.0',
                'time' => $time,
                'authcode' => $authcode,
                'sign' => $sign,
            );

            $result = json_decode($this->vendorsend->send('pussy888_transafer', $url, 'GET', $data), true);
            if (is_array($result) && $result['success'] == 'true' && $result['code'] == '0') {
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
     * 取得sign
     */
    private function getsign($authcode, $username, $time, $secretKey)
    {
        $sign = "";
        $sign = "$authcode$username$time$secretKey";
        $sign = strtolower($sign);
        return strtoupper(md5($sign));
    }
}
