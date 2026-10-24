<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorNpt extends Vendor
{
    private $api_url;
    private $admin_name;
    private $entity_key;
    private $player_prefix;
    private $cert_path;

    public function __construct($info)
    {
        $this->name = 'NPT';
        $this->vendor_id = 41;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->admin_name = $info->ADMIN_NAME;
        $this->entity_key = $info->ENTITY_KEY;
        $this->cert_path = $info->CERT_PATH;
        $this->player_prefix = $info->PLAYER_PREFIX;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = '/player/balance';
            $data = array(
                "playername" => $this->player_prefix . $username
            );

            $result = $this->post($data, $url);
            if (is_array($result) && !isset($result['error']) && isset($result['result']['balance'])) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['result']['balance']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => isset($result['error']) ? $result['error'] : __('error_message.9996')
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
            if ($type == 1) {
                // 存款
                $url = '/player/deposit';
            } else {
                // 提款前強制登出
                $url = 'player/logout';
                $data = array(
                    "playername" => $this->player_prefix . $username
                );
                $result = $this->post($data, $url);
                // 提款
                $url = '/player/withdraw';
            }
            $data = array(
                "playername" => $this->player_prefix . $username,
                "adminname" => $this->admin_name,
                "amount" => $credit < 1 ? floatval($credit) : $credit,
                "externaltranid" => $billNo
            );

            $result = $this->post($data, $url);
            if (is_array($result) && !isset($result['error'])) {
                // 轉帳成功取餘額
                return true;
            } else {
                // 轉帳失敗
                return isset($result['error']) ? $result['error'] : __('error_message.9996');
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
    public function getGameUrl($username, $password, $gameType, $client, $language)
    {
        try {
            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'username' => $username,
                    'password' => $password,
                    'lang' => $language,
                    'mode' => 'real',
                    'game' => $gameType,
                    'client' => $client
                ],
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

    private function post($data, $url)
    {
        Common::log(0, $data, 'npt_' . $url);

        $postFile = http_build_query($data);

        $path = base_path();
        $header[] = "X_ENTITY_KEY: " . $this->entity_key;
        $header[] = "Content-Type: application/x-www-form-urlencoded";

        $tuCurl = curl_init();
        curl_setopt($tuCurl, CURLOPT_URL, $this->api_url . $url);
        curl_setopt($tuCurl, CURLOPT_VERBOSE, 0);
        curl_setopt($tuCurl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($tuCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($tuCurl, CURLOPT_POSTFIELDS, $postFile);
        curl_setopt($tuCurl, CURLOPT_SSLCERT, $path . $this->cert_path . '.pem');
        curl_setopt($tuCurl, CURLOPT_SSLKEY, $path . $this->cert_path . '.key');
        $result = curl_exec($tuCurl);
        curl_close($tuCurl);

        Common::log(0, ['request' => $data, 'response' => $result], 'npt_' . $url);
        return json_decode($result, true);
    }
}