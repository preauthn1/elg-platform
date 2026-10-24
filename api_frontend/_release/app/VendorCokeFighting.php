<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorCokeFighting extends Vendor
{
    public function __construct($info)
    {
        $this->name = 'Coke Fighting';
        $this->vendor_id = 9;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
    }

    /**
     * -取餘額(使用db的balance)
     * }
     */
    public function getBalance($username, $password = '')
    {
        try {
            // 取用戶資料
            $result = CokeFightingAccount::where('username', $username)->first();
            if (is_object($result)) {
                $log_data = array(
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->balance
                );
            } else {
                $log_data = array(
                    'code' => 9995,
                    'message' => __('error_message.9995')
                );
            }
            return $log_data;
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

    /**
     * -進入遊戲
     */
    public function getGameUrl($username)
    {
        try {
            // 取KEY
            $url = 'api/player/' . env('COKE_FIGHTING_SITE') . '/getKey';
            $data = array();
            $data['user'] = $username;
            $data['currency'] = env('COKE_FIGHTING_CURRENCY');
            $result = $this->post($url, $data);
            if ($result['status'] == 1) {
                $url = env('COKE_FIGHTING_URL') . 'api/player/' . env('COKE_FIGHTING_SITE') . '/login';
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => [
                        'url' => $url,
                        'user' => $username,
                        'key' => $result['key'],
                        'extension1' => env('COKE_FIGHTING_EXTENSION')
                    ]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result
                ];
            }
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

    /**
     * -轉帳(使用db餘額，遊戲商本身沒有錢包)
     */
    public function transafer($username, $password, $type, $credit)
    {
        try {
            if ($type == 1) {
                //轉入遊戲商
                $result = CokeFightingAccount::where('username', $username)->increment('balance', $credit);
            } else {
                //轉出遊戲商
                $result = CokeFightingAccount::where('username', $username)->decrement('balance', $credit);
            }
            if ($result) {
                return true;
            } else {
                return false;
            }
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

    private function post($url, $data)
    {
        $data['cert'] = env('COKE_FIGHTING_CERT');
        $data['agent'] = env('COKE_FIGHTING_AGENT');

        Common::log(0, $data, $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('COKE_FIGHTING_URL') . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], $url);
        return json_decode($result, true);
    }
}