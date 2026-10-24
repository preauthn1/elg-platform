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
*/

class VendorCt extends Vendor
{
    private $apiUrl;
    private $agentName;
    private $apiKey;
    protected $vendorsend;

    public function __construct($info)
    {
        $this->name = 'CT';
        $this->vendor_id = 27;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendorsend = new VendorSend(['json_post' => true]);
        $this->apiUrl = $info->API_URL;
        $this->agentName = $info->AGENT_NAME;
        $this->apiKey = [REDACTED];
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $url = $this->apiUrl . 'api/login/' . $this->agentName . '/';
            $random = md5(uniqid());
            $token = [REDACTED] . $this->apiKey . $random);
            $params = [
                'random' => $random,
                'token' => $token,
                'lang' => $language,
                'member' => ['username'=>$username]
            ];

            $headers = array('Content-Type: application/json');
            $result = json_decode($this->vendorsend->send('ct_getGameUrl', $url, 'POST', $params, $headers), true);
            if (is_array($result) && $result['codeId'] === 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['list'][1] . $result['token'],
                    'url' => ['pc'=>$result['list'][0] . $result['token']]
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
            $url = $this->apiUrl . 'api/getBalance/' . $this->agentName . '/';
            $random = md5(uniqid());
            $token = [REDACTED] . $this->apiKey . $random);
            $params = [
                'random' => $random,
                'token' => $token,
                'member' => ['username'=>$username]
            ];
            $headers = array('Content-Type: application/json');
            $result = json_decode($this->vendorsend->send('ct_getBalance', $url, 'POST', $params, $headers), true);
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
            $url = $this->apiUrl . 'api/transfer/' . $this->agentName . '/';
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
            $response = $this->vendorsend->send('ct_transfer', $url, 'POST', $params, $headers);
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
        $url = $this->apiUrl . 'api/checkTransfer/' . $this->agentName . '/';
        $uniqid = uniqid();
        $random = md5($uniqid);
        $token = [REDACTED] . $this->apiKey . $random);
        $params = [
            'random' => $random,
            'token' => $token,
            'data' => $trans_no,
        ];
        $headers = array('Content-Type: application/json');
        $result = json_decode($this->vendorsend->send('ct_check_transfer', $url, 'POST', $params, $headers), true);
        if (is_array($result) && $result['codeId'] === 0) {
            //轉帳成功
            return true;
        } else {
            return false;
        }
    }
}
