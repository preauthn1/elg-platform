<?php

namespace App;

use App\Helpers\Common;
use App\Helpers\VendorSend;

class VendorAes extends Vendor
{
    private $api_url;
    private $cert;
    private $agent;
    private $auto_bet_mode;
//    private $header;
    protected $vendorsend;

    public function __construct($info)
    {
        $this->name = 'AES';
        $this->vendor_id = 25;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendorsend = new VendorSend();
        $this->api_url = $info->API_URL;
        $this->cert = $info->CERT;
        $this->agent = $info->AGENT;
        $this->bet_limit = $info->BETLIMIT;
        $this->auto_bet_mode = isset($info->AUTO_BET_MODE) ? $info->AUTO_BET_MODE : 1;
//        $this->header = array('Content-Type : application/x-www-form-urlencoded; charset=UTF-8');
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = $this->api_url . '/wallet/getBalance';

            $data = array(
                'cert' => $this->cert,
                'agentId' => $this->agent,
                'userIds' => $username,
            );
            $result = json_decode($this->vendorsend->send('aes_get_balance', $url, 'POST', $data), true);
            if (is_array($result) && $result['status'] == '0000') {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['results'][0]['balance'],
                ];
            } else {
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
    public function transafer($username, $password= '', $type, $vendor_point, $bill_no)
    {
        try {
            $url = $this->api_url;
            $data = array();
            if ($type == 1) {
                //存款
                $url .= '/wallet/deposit';
            } else {
                //提款
                $url .= '/wallet/withdraw';
                $data['withdrawType'] = 0;
            }

            $data['cert'] = $this->cert;
            $data['userId'] = $username;
            $data['agentId'] = $this->agent;
            $data['txCode'] = $bill_no;
            $data['transferAmount'] = $vendor_point;

            $result = json_decode($this->vendorsend->send('aes_transafer', $url, 'POST', $data), true);
            if (is_array($result) && $result['status'] == '0000') {
                //轉帳成功
                return true;
            } else {
                //轉帳失敗
                return [
                    'code' => 9996,
                    //'message' => $result ? $result : __('error_message.9996')
                    'message' => $result['desc'],
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
     * -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language, $is_pad)
    {
        try {
            $url = $this->api_url . '/wallet/doLoginAndLaunchGame';

            $data = array(
                'cert' => $this->cert,
                'agentId' => $this->agent,
                'userId' => $username,
                'gameCode' => $game_type,
                'gameType' => 'LIVE',
                'platform' => 'SEXYBCRT',
                'language' => $language,
                'betLimit' => json_encode(array('SEXYBCRT' => array('LIVE' => array('limitId'=>array($this->bet_limit))))),
                'autoBetMode' => $this->auto_bet_mode
            );
            //如果是平板，要多帶這個參數
            if($is_pad){
                $data['isMobileLogin'] = $is_pad;
            }

            $result = json_decode($this->vendorsend->send('aes_get_gameurl', $url, 'POST', $data), true);

            if (is_array($result) && $result['status'] == "0000") {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['url'],
                    'url' => ['moblie' => $result['url'], 'pc' => $result['url']],
                ];
            } else {
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
}
