<?php

namespace App;

use DB;
use Hash;
use App\Helpers\Common;
use App\Models\Ace333Account;

class VendorAce333 extends Vendor
{
    private $api_url;
    private $token;
    private $currency;

    public function __construct($info)
    {
        $this->name = 'ACE333';
        $this->vendor_id = 6;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->token = [REDACTED];
        $this->currency = $info->CURRENCY;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($playerId)
    {
        try {
            $url = 'api/getbalance';
            $data = array();
            $data['playerID'] = $playerId;
            $result = $this->post($url, $data, 'get');
            if (is_array($result) && $result['error'] == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['balance']
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
    public function transafer($username, $password, $type, $credit, $billNo)
    {
        try {
            $data = array();
            if ($type == 1) {
                // type 1 儲值(4.2)
                $url = 'api/topup';
                $data['topUpAmount'] = $credit;
            } else {
                // type 2 提款(2.7)
                $url = 'api/withdraw';
                $data['withdrawAmount'] = $credit;
            }
            $data['playerID'] = $username;
            $data['referenceID'] = $billNo;
            $data['currency'] = $this->currency;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['error'] == 0) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $result['description'];
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
     * -修改密碼
     */
    public function resetPassword($username, $newPassword)
    {
        try {
            $row = Ace333Account::where('username', $username)->first();
            if (is_object($row)) {
                $rs = $row->update(['password' => Hash::make($newPassword)]);

                if ($rs) {
                    return [
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ];
                } else {
                    return [
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ];
                }
            } else {
                return [
                    'code' => 1005,
                    'message' => __('error_message.1005')
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

    private function post($url, $data, $action = 'post')
    {
        Common::log(0, $data, 'ACE333_' . $url);
        $ch = curl_init();
        if ($action == 'post') {
            curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else {
            curl_setopt($ch, CURLOPT_URL, $this->api_url . $url . '?' . http_build_query($data));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'token:' . $this->token,
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'ACE333_' . $url);
        curl_close($ch);
        return json_decode($result, true);
    }
}
