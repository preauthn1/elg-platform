<?php

namespace App;

use DB;
use App\Helpers\Common;
use App\RSA;

class VendorEbet extends Vendor
{
    private $apiUrl;
    private $channelId;
    private $subChannelId;
    private $currency;
    private $html5Url;
    private $privateKey;
    private $sleep_second;
    private $max_loop_time;
    private $slot_vendors;
    private $use_api_url;

    public function __construct($info)
    {
        $this->name = 'EBet';
        $this->vendor_id = 13;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
        $this->slot_vendors = array('genesis', 'radi8', 'bunfox');

        $this->apiUrl = $info->API_URL;
        $this->channelId = $info->CHANNEL_ID;
        $this->subChannelId = $info->SUB_CHANNEL_ID;
        $this->currency = $info->CURRENCY;
        $this->html5Url = $info->HTML5_URL;
        $this->privateKey = $info->PRIVATE_KEY;
        $this->sleep_second = isset($info->SLEEP_SECOND) ? $info->SLEEP_SECOND : 5;
        $this->max_loop_time = isset($info->API_LOOP_TIME) ? $info->API_LOOP_TIME : 3;
        $this->use_api_url = isset($info->USE_API_URL) ? $info->USE_API_URL : 0;
    }

    /**
     * -取餘額
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = 'userinfo';
            $timestamp = date('U');

            $data = array();
            $data['channelId'] = $this->channelId;
            $data['subChannelId'] = $this->subChannelId;
            $data['username'] = $username;
            $data['timestamp'] = $timestamp;
            $data['signature'] = $this->getSign($username . $timestamp);
            $data['currency'] = $this->currency;
            $result = $this->post($data, $url);
            if (is_array($result) && $result['status'] == '200') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['money']
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
            $url = 'recharge';
            $timestamp = date('U');

            $data = array();
            $data['channelId'] = $this->channelId;
            $data['subChannelId'] = $this->subChannelId;
            $data['username'] = $username;
            $data['money'] = ($type == 1) ? $credit : -$credit;
            $data['rechargeReqId'] = $billNo;
            $data['timestamp'] = $timestamp;
            $data['signature'] = $this->getSign($username . $timestamp);
            $data['currency'] = $this->currency;
            $data['typeId'] = 0;
            $result = $this->post($data, $url);
            //不管recharge回傳成功或失敗,檢查ebet此充值紀錄是否存在
            $transferStatue = $this->checkTransferStatus($billNo);
            if ($transferStatue) {
                //存在回傳成功
                return true;
            } else {
                return false;
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
    public function getGameUrl($username, $token, $gameType, $language, $remark = '')
    {
        try {
            $gameUrl = '';
            $timestamp = time();
            if (in_array($remark, $this->slot_vendors)) {
                // 老虎機是用其他的API
                $url = 'loginslot';

                $data = array();
                $data['channelId'] = $this->channelId;
                $data['loginEventType'] = 4;
                $data['timestamp'] = $timestamp;
                $data['gameID'] = $gameType;
                $data['providerId'] = $remark;
                $data['signature'] = $this->getSign($username . $timestamp);
                $data['username'] = $username;
                $data['token'] = md5($token);

                $result = $this->post($data, $url);

                if (is_array($result) && $result['status'] == 200) {
                    $gameUrl = $result['slotURL'];
                }
            } else {
                if ($this->use_api_url == 1) {
                    $url = 'launchUrl';
                    $data = array();
                    $data['channelId'] = $this->channelId;
                    $data['timestamp'] = $timestamp;
                    $data['signature'] = $this->getSign($this->channelId . $timestamp);
                    $data['currency'] = $this->currency;
//                    $data['china'] = 1;
                    
                    $result = $this->post($data, $url);
                    if (is_array($result) && $result['status'] == 200) {
                        $gameUrl = $result['launchUrl'] . '?orientation=2&language=' . $language . '&gameType=' . $gameType . '&username=' . $username . '&accessToken=' . md5($token);;
                    }
                } else {
                    $gameUrl = $this->html5Url . '&orientation=2&language=' . $language . '&gameType=' . $gameType . '&username=' . $username . '&accessToken=' . md5($token);
                }
            }

            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $gameUrl,
                'url' => ['mobile' => $gameUrl, 'pc' => $gameUrl]
            ];
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

    private function checkTransferStatus($billNo)
    {
        $loopTime = 0;

        do {
            $nextLoop = false;
            $loopTime++;
            $url = 'rechargestatus';
            $data = array();
            $data['channelId'] = $this->channelId;
            $data['rechargeReqId'] = $billNo;
            $data['signature'] = $this->getSign($billNo);
            $data['currency'] = $this->currency;
            $result = $this->post($data, $url);
            if (is_array($result) && $result['status'] == '200') {
                return true;
            }
            if ($result['status'] == -1 && $loopTime <= $this->max_loop_time) {
                $nextLoop = true;
                sleep($this->sleep_second);
            }
        } while ($nextLoop);

        return false;
    }

    private function getSign($string)
    {
        $rsa = new RSA('', str_replace('\\n', "\n", $this->privateKey));
        return $rsa->sign($string);
    }

    private function post($data, $url)
    {
        $requestbody = json_encode($data);
        Common::log(0, $data, 'EBET_' . $url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestbody);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json'
        ));
        $result = curl_exec($ch);

        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'EBET_' . $url);
        return json_decode($result, true);
    }

    private function errorCode($key)
    {
        $array = [
            '-1' => 'Recharge record no exist',
            '0' => 'Recharging',
            '200' => 'Successful',
            '201' => 'Repeated rechargeId',
            '202' => 'Channel no exist',
            '203' => 'RechargeId can’t null',
            '204' => 'Recharge money can’t null',
            '205' => 'Recharge money too small',
            '207' => 'Refund Money Inconsistent',
            '208' => 'Record is not existed',
            '209' => 'Check record already refund',
            '401' => 'User or password error',
            '410' => 'Token error',
            '500' => 'Server error',
            '505' => 'Channel under maintenance',
            '4003' => 'System busy, try back later',
            '4026' => 'Signature error',
            '4027' => 'IP is not authorized',
            '4028' => 'Trial user can’t recharge credit',
            '4029' => 'Function disable',
            '4030' => 'Data type error',
            '4037' => 'User not existence',
            '4038' => 'Frequent request',
            '4202' => 'Visit count no enough',
            '5001' => 'Recharge failed',
            '5002' => 'Not enough cash',
            '5003' => 'Sub channel no exist',
            '5005' => 'Wallet not enabled',
            '5006' => 'Unable to extract in the game',
        ];
        return ($array[$key] != '') ? $array[$key] : $key;
    }
}