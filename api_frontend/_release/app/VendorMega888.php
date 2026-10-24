<?php

namespace App;

use UUID;
use Hash;
use App\Helpers\Common;
use App\Models\Mega888Account;

class VendorMega888 extends Vendor
{
    private $api_url;
    private $api_key;
    private $sn;
    private $account;

    public function __construct($info)
    {
        $this->name = 'Mega888';
        $this->vendor_id = 10;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->api_key = [REDACTED];
        $this->sn = $info->SN;
        $this->account = $info->ACCOUNT;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {

            $url = 'open.mega.balance.get';
            $digest = $this->digest($username);
            $data = array();
            $data['random'] = $digest['random'];
            $data['digest'] = $digest['digest'];
            $data['sn'] = $this->sn;
            $data['loginId'] = $username;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['error'] == null) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['result']
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
            $url = 'open.mega.balance.transfer';
            $credit = ($type == 1) ? $credit : -$credit;
            $digest = $this->digest($username . $credit);
            $data = array();
            $data['random'] = $digest['random'];
            $data['digest'] = $digest['digest'];
            $data['sn'] = $this->sn;
            $data['loginId'] = $username;
            $data['amount'] = $credit;
            $data['bizId'] = $billNo;
            $data['checkBizId'] = 0;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['error'] == null) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $result['error']['message'];
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
     * -修改密碼(改自己的db)
     */
    public function resetPassword($username, $newPassword)
    {
        try {
            $row = Mega888Account::where('username', $username)->first();
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

    /**
     * -取APP下載連結
     */
    public function getAppUrl()
    {
        try {
            $url = 'example.invalid.url.download';
            $digest = $this->digest('');
            $data = array();
            $data['random'] = $digest['random'];
            $data['digest'] = $digest['digest'];
            $data['sn'] = $this->sn;
            $data['agentLoginId'] = $this->account;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['error'] == null) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['result']
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
     * -取簽名
     */
    private function digest($string)
    {
        $random = UUID::generate()->string;
        $result = [
            'random' => $random,
            'digest' => strtoupper(md5($random . $this->sn . $string . $this->api_key))
        ];
        return $result;
    }

    private function post($url, $data)
    {
        $post = [
            'id' => $data['random'],
            'method' => $url,
            'params' => $data,
            'jsonrpc' => '2.0',
        ];
        Common::log(0, $post, 'Mega888_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);

        curl_close($ch);
        Common::log(0, ['request' => $post, 'response' => $result], 'Mega888_' . $url);
        return json_decode($result, true);
    }
}