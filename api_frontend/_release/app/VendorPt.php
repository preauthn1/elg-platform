<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorPt extends Vendor
{
    private $host;
    private $adminName;
    private $kioskName;
    private $entityKey;
    private $certPath;
    private $mobile_domain;
    private $system_id;

    public function __construct($info)
    {
        $this->name = 'PT';
        $this->vendor_id = 17;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->host = $info->HOST;
        $this->adminName = $info->ADMIN_NAME;
        $this->kioskName = $info->KIOSK_NAME;
        $this->entityKey = $info->ENTITY_KEY;
        $this->certPath = $info->CERT_PATH;
        $this->mobile_domain = $info->MOBILE_DOMAIN;
        $this->system_id = $info->SYSTEM_ID;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getBalance($username)
    {
        try {
            $url = 'player/info/';
            $data = array();
            $data['playername'] = $username;
            $result = $this->post($data, $url);
            if (is_array($result) && !isset($result['error']) && isset($result['result']['BALANCE'])) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result['result']['BALANCE']
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
                $url = 'player/deposit/';
            } else {
                // 提款前強制登出
                $url = 'player/logout/';
                $data = array();
                $data['playername'] = $username;
                $result = $this->post($data, $url);
                // 提款
                $url = 'player/withdraw/';
            }
            $data = array();
            $data['playername'] = $username;
            $data['adminname'] = $this->adminName;
            $data['amount'] = $credit < 1 ? floatval($credit) : $credit;
            $data['externaltranid'] = $billNo;
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
    public function getGameUrl($username, $password, $gameType, $language)
    {
        try {
            return [
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'username' => $username,
                    'password' => $password,
                    'game_type' => $gameType,
                    'mobile_domain' => $this->mobile_domain,
                    'system_id' => $this->system_id,
                    'language' => $language
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
        Common::log(0, $data, 'pt_' . $url);
        $path = base_path();
        $header = array();
        $header[] = "Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
        $header[] = "Cache-Control: max-age=0";
        $header[] = "Connection: keep-alive";
        $header[] = "Keep-Alive:timeout=5, max=100";
        $header[] = "Accept-Charset:ISO-8859-1,utf-8;q=0.7,*;q=0.3";
        $header[] = "Accept-Language:es-ES,es;q=0.8";
        $header[] = "Pragma: ";
        $header[] = "X_ENTITY_KEY: " . $this->entityKey;
        $tuCurl = curl_init();
        curl_setopt($tuCurl, CURLOPT_URL, $this->host . $url . str_replace(['=', '&'], '/', http_build_query($data)));
        curl_setopt($tuCurl, CURLOPT_PORT, 443);
        curl_setopt($tuCurl, CURLOPT_VERBOSE, 0);
        curl_setopt($tuCurl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($tuCurl, CURLOPT_SSLCERT, $path . $this->certPath . '.pem');
        curl_setopt($tuCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($tuCurl, CURLOPT_SSLKEY, $path . $this->certPath .  '.key');
        $result = curl_exec($tuCurl);
        curl_close($tuCurl);
        Common::log(0, ['request' => $data, 'response' => $result], 'pt_' . $url);
        return json_decode($result, true);
    }
}