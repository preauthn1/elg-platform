<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Services\TelegramService;
use DB;
use Hash;
use log;

class VendorMgc extends Vendor
{
    private $api_url;
    private $merchant_id;
    private $api_key;

    public function __construct($info)
    {
        $this->name = 'MGC';
        $this->vendor_id = 51;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend(['json_post' => 'array']);

        $this->api_url = $info->API_URL;
        $this->merchant_id = $info->Merchant_ID;
        $this->api_key = [REDACTED];
    }

    /**
     *  -啟動遊戲
     */
    public function getAppUrl($username)
    {
        try {

            $url = $this->api_url . '/game/login';
            $action = 'MGC_Game';

            $data = array(
                'playerId' => $username
            );

            $postData = $this->postData($data);

            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData), true);
            if (is_array($response) && $response['code'] == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response['data']['token']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response['message']
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
     * - 取帳號資料(Get User Detail)
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = $this->api_url . '/game/queryMoney';
            $action = 'MGC_Balance';

            $data = array(
                'playerId' => $username
            );

            $postData = $this->postData($data);

            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData), true);
            if (is_array($response) && $response['code'] == 0) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response['data']['money']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response['message']
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
            if ($type == 1) {
                $url = $this->api_url . '/mgcgame/transferTo';
                $action = $this->name . '_Deposit';
            } else {
                $url = $this->api_url . '/mgcgame/transferOut';
                $action = $this->name . '_Withdrawal';
            }

            $data = array(
                'playerId' => $username,
                'money' => $credit,
                'orderId' => $billNo
            );

            $postData = $this->postData($data);

            $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData), true);
            if (is_array($response) && $response['code'] == '0') {
                // 轉帳成功取餘額
                if ($response['data']['status'] == '1') {
                    return true;
                } elseif ($response['data']['status'] == '0' || $response['data']['status'] == '2') {
                    // 如果status是0或2 代表轉入失敗 要退還玩家分數
                    return 'fail';
                } else {
                    // 如果是3表示這筆交易還未處理,使用他們確認訂單的API確認狀態
                    $result = $this->checkOrderResult($billNo);
                    return $result;
                }
            } else {
                // 轉帳失敗,紀錄log後回傳空值
                $context = [
                    'request' => [
                        'url' => $url,
                        'parameters' => $data,
                    ],
                    'response' => (array)$response
                ];
                log::error($this->name . '_transfer_fail', $context);
                return '';
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            log::error($this->name . '_exception', ['code' => $info[0], 'message' => $info[2]]);
            return '';
        } catch (\Exception $e) {
            log::error($this->name . '_exception', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
            return '';
        }
    }

    private function postData($params)
    {
        //參數要依照字母排序
        ksort($params);
        return ['mchid' => $this->merchant_id, 'sign' => $this->sign($params), 'params' => json_encode($params)];
    }

    private function sign($params)
    {
        $paramStr = http_build_query($params);
        $message = $this->merchant_id . $paramStr . $this->api_key;
        //加密要回傳大寫
        return strtoupper(md5($message));
    }

    private function checkOrderResult($orderId)
    {
        $url = $this->api_url . '/api/getApiOrderLists';
        $action = 'mgc_check_order';

        $data = array(
            'orderIds' => $orderId
        );

        $postData = $this->postData($data);

        $response = json_decode($this->vendor_send->send($action, $url, 'POST', $postData), true);
        if (is_array($response) && $response['code'] == '0') {
            if ($response['data']['0']['status'] == '1') {
                return true;
            } elseif ($response['data']['0']['status'] == '0' || $response['data']['0']['status'] == '2') {
                return 'fail';
            } else {
                // 如果是3表示這筆交易還未處理,傳TG通知
                $message = '【MGC訂單未處理】 ' .
                    '訂單編號: ' . $response['data']['0']['orderId'] . PHP_EOL .
                    '玩家帳號: ' . $response['data']['0']['playerId'] . PHP_EOL .
                    '金額: ' . $response['data']['0']['amount'] . PHP_EOL .
                    '狀態：' . $response['data']['0']['status'];

                $tgService = new TelegramService();
                $tgService->sendMessage($message, 'warn');
                return '';
            }
        } else {
            $context = [
                'request' => [
                    'url' => $url,
                    'parameters' => $data,
                ],
                'response' => (array)$response
            ];
            log::error($this->name . '_check_order_fail', $context);
            return '';
        }
    }

}