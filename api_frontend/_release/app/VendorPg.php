<?php

namespace App;

use APP\Helpers\Common;
use SoapClient;

class VendorPg extends Vendor
{
    private $api_url;
    private $login;
    private $password;
    private $pid;
    private $currency;

    public function __construct($info)
    {
        $this->name = 'PG';
        $this->vendor_id = 20;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->login = $info->API_LOGIN;
        $this->password = [REDACTED];
        $this->pid = $info->API_PID;
        $this->currency = $info->Currency;
    }

    /**
     *  -啟動遊戲
     */
    public function getGameUrl($username, $game_type, $language)
    {
        try {
            $data = array();
            $data['ExternalUserId'] = $username;
            $result = $this->post($data, 'GetTicket');
            if (is_object($result) && isset($result->Ticket)) {
                $ticket = $result->Ticket;
                $url = 'https://example.invalid/casino/ContainerLauncher?pid=' . $this->pid . '&gameid=' . $game_type . '&channel=mobile&lang=' . $language . '&ticket=' . $ticket . '&practice=0';
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $url,
                    'url' => ['moblie' => $url, 'pc' => $url],
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
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $data = array();
            $data['ExternalUserId'] = $username;
            $result = $this->post($data, 'Balance');
            if (is_object($result) && isset($result->UserBalance)) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->UserBalance->Real,
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
    public function transafer($username, $password = '', $type, $vendor_point, $bill_no)
    {
        try {
            if ($type == 1) {
                //存款
                $method = 'CreditAccount';
            } else {
                //提款
                $method = 'DebitAccount';
            }
            $data = array();
            $data['ExternalUserId'] = $username;
            $data['Amount'] = $vendor_point;
            $data['Currency'] = $this->currency;
            $data['ExternalTransactionId'] = $bill_no;
            $result = $this->post($data, $method);
            if (is_object($result) && isset($result->UserAccount)) {
                return true;
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

    private function post($data, $method)
    {
        $wsdl = $this->api_url;
        $info_data = $data;
        $info_data['wsdl'] = $wsdl;
        $info_data['login'] = $this->login;
        $info_data['password'] = $this->password;
        Common::log(0, $info_data, 'PG_' . $method);
        $option = array(
            "ssl" => array('ciphers' => 'RC4-SHA', 'verify_peer' => false, 'verify_peer_name' => false),
            'location' => $wsdl,
            "login" => $this->login,
            "password" => $this->password,
            'trace' => 1,
        );
        $client = new SoapClient($wsdl, $option);
        $result = $client->$method($data);
        Common::log(0, ['request' => $info_data, 'response' => $result], 'PG_' . $method);
        return $result;
    }
}
