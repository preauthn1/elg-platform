<?php

namespace App;

use App\Helpers\VendorSend;
use DB;
use APP\Helpers\Common;

class VendorVSPoker extends Vendor
{
    private $api_url;
    private $launch_url;
    private $opcode;
    private $secret_key;
    private $vendor_send;

    function __construct($info)
    {
        $this->name = 'VSPoker';
        $this->vendor_id = 36;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->vendor_send = new VendorSend();

        $this->api_url = $info->API_URL;
        $this->launch_url = $info->LAUNCH_URL;
        $this->opcode = $info->OPCODE;
        $this->secret_key = [REDACTED];
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type)
    {
        try {
            $url = $this->api_url . '/processPlayerLoginByUsername.shtml';
            $action = 'VSPoker_get_gameUrl';
            $function = 'ProcessPlayerLogin';

            $data = array();
            $data['username'] = $username;
            $data['reqDateTime'] = date('Y-m-d H:i:s');
            $data['opCode'] = $this->opcode;
            $securityToken = [REDACTED]'username'] . $function . $data['reqDateTime']);
            $data['securityToken'] = $securityToken;

            $response = $this->vendor_send->send($action, $url, 'POST', $data);
            if (is_string($response) && $response != -1) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $this->launch_url . $response,
                    'url' => ['moblie' => $this->launch_url, 'pc' => $this->launch_url]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response
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
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = $this->api_url . '/processPlayerBalanceReqByUsername.shtml';
            $action = 'VSPoker_Balance';
            $function = 'ProcessPlayerBalance';

            $data = array();
            $data['username'] = $username;
            $data['reqDateTime'] = date('Y-m-d H:i:s');
            $data['opCode'] = $this->opcode;
            $securityToken = [REDACTED]'username'] . $function . $data['reqDateTime']);
            $data['securityToken'] = $securityToken;

            $response = $this->vendor_send->send($action, $url, 'POST', $data);
            if (is_string($response) && $response != -1) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $response
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response
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
            $url = $this->api_url;
            if ($type == 1) {
                $url .= '/processPlayerTransferInReqByUsername.shtml';
                $function = 'ProcessPlayerTransferIn';
                $action = 'VSPoker_Deposit';
            } else {
                $url .= '/processPlayerTransferOutReqByUsername.shtml';
                $function = 'ProcessPlayerTransferOut';
                $action = 'VSPoker_Withdrawal';
            }
            $data = array();
            $data['username'] = $username;
            $data['amount'] = $credit;
            $data['transId'] = $billNo;
            $data['reqDateTime'] = date('Y-m-d H:i:s');
            $data['opCode'] = $this->opcode;
            $securityToken = [REDACTED]'username'] . $data['amount'] . $data['transId'] . $function . $data['reqDateTime']);
            $data['securityToken'] = $securityToken;

            $response = $this->vendor_send->send($action, $url, 'POST', $data);
            if (is_string($response) && $response != -1) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return $response;
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

    private function sign($str)
    {
        $md5Str = $str . $this->opcode . $this->secret_key;
        return md5($md5Str);
    }
}