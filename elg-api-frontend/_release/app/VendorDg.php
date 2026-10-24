<?php

namespace App;

use App\Helpers\VendorSend;
use log;

/*
$info = [
    'API_URL' => 'http://example.invalid/',
    'AGENT_NAME' => 'CTTE000172',
    'API_KEY' => '5af014f965b2492ab00797fec7952810',
    'TABLE_LIMIT' => 'A',
    'CURRENCY_NAME' => 'MYR'
];
{"API_URL":"http:\/\/example.invalid\/","AGENT_NAME":"DGTE01014U","API_KEY":"ab75c0a4cfb4461bb428a544087987c5","TABLE_LIMIT":"A","CURRENCY_NAME":"AUD"}
*/

class VendorDg extends Vendor
{
    private $apiUrl;
    private $agentName;
    private $apiKey;
    protected $vendorsend;

    public function __construct($info)
    {
        $this->name = 'DG';
        $this->vendor_id = 29;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendorsend = new VendorSend(['json_post' => true]);
        $this->apiUrl = isset($info->API_URL) ? $info->API_URL : '';
        $this->agentName = isset($info->AGENT_NAME) ? $info->AGENT_NAME : '';
        $this->apiKey = [REDACTED] ? $info->API_KEY : '';
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $language)
    {
        try {
            $url = $this->apiUrl . 'user/login/' . $this->agentName . '/';
            $random = md5(uniqid());
            $token = [REDACTED] . $this->apiKey . $random);
            $params = [
                'random' => $random,
                'token' => $token,
                'lang' => $language,
                'member' => ['username'=>$username]
            ];

            $headers = array('Content-Type: application/json');
            $json = $this->vendorsend->send('dg_getGameUrl', $url, 'POST', $params, $headers);
            $result = json_decode($json, true);
            if (is_array($result) && $result['codeId'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['list'][1] . $result['token'],
                    'url' => $result['list'][0] . $result['token']
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
            $url = $this->apiUrl . 'user/getBalance/' . $this->agentName . '/';
            $random = md5(uniqid());
            $token = [REDACTED] . $this->apiKey . $random);
            $params = [
                'random' => $random,
                'token' => $token,
                'member' => ['username'=>$username]
            ];
            $headers = array('Content-Type: application/json');
            $result = json_decode($this->vendorsend->send('dg_getBalance', $url, 'POST', $params, $headers), true);
            if (is_array($result) && $result['codeId'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['member']['balance'],
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
            $url = $this->apiUrl . 'account/transfer/' . $this->agentName . '/';
            $amount = 0;
            if($type == 1) {
                $amount = $vendor_point;
            } else {
                $amount = -$vendor_point;
            }
            //產生訂單編號
            $uniqid = uniqid();
            $trans_no = 'T' . $uniqid;
            $random = md5($uniqid);
            $token = [REDACTED] . $this->apiKey . $random);
            $params = [
                'random' => $random,
                'token' => $token,
                'data' => $trans_no,
                'member' => ['username'=>$username, 'amount'=>$amount]
            ];
            $headers = array('Content-Type: application/json');
            $response = $this->vendorsend->send('dg_transfer', $url, 'POST', $params, $headers);
            $result = json_decode($response, true);
            if (is_array($result) && $result['codeId'] === 0) {
                //轉帳成功
                return true;
            } else if (!$response) { //未收到api response
                if($this->checkTransferSuccess($trans_no)) {
                    //轉帳成功
                    return true;
                } else {
                    //轉帳失敗
                    return [
                        'code' => 9996,
                        'message' => 'no response',
                    ];
                }
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

    /**
     * 檢查轉分是否成功
     */
    private function checkTransferSuccess($trans_no) {
        $url = $this->apiUrl . 'account/checkTransfer/' . $this->agentName . '/';
        $uniqid = uniqid();
        $random = md5($uniqid);
        $token = [REDACTED] . $this->apiKey . $random);
        $params = [
            'random' => $random,
            'token' => $token,
            'data' => $trans_no,
        ];
        $headers = array('Content-Type: application/json');
        $result = json_decode($this->vendorsend->send('dg_check_transfer', $url, 'POST', $params, $headers), true);
        if (is_array($result) && $result['codeId'] === 0) {
            //轉帳成功
            return true;
        } else {
            return false;
        }
    }
}
