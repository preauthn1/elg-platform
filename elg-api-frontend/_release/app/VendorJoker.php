<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorJoker extends Vendor
{
    private $api_url;
    private $app_id;
    private $secret;

    public function __construct($info)
    {
        $this->name = 'Joker';
        $this->vendor_id = 2;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->app_id = $info->APP_ID;
        $this->secret = [REDACTED];
    }

    /**
     * -取餘額
     */
    public function getBalance($username, $password = '')
    {
        try {
            // Get Credit
            $data = array();
            $data['Method'] = 'GC';
            $data['Timestamp'] = date('U');
            $data['Username'] = $username;
            ksort($data);
            $result = $this->post($data);
            if ($result != false && is_array($result)) {
                if (isset($result['Credit'])) {
                    return [
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $result['Credit']
                    ];
                } else {
                    return [
                        'code' => 9996,
                        'message' => $result
                    ];
                }
            } else {
                return [
                    'code' => 9995,
                    'message' => __('error_message.9995')
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
     * -設定密碼
     */
    public function resetPassword($username, $password)
    {
        try {
            // Get Credit
            $data = array();
            $data['Method'] = 'SP';
            $data['Password'] = $password;
            $data['Timestamp'] = date('U');
            $data['Username'] = $username;
            ksort($data);
            $result = $this->post($data);
            if ($result != false && is_array($result)) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ];
            } else {
                return [
                    'code' => 9995,
                    'message' => __('error_message.9995')
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
     * -加密直操開啟app的資料
     */
//    public function getAppEncryptData($username, $password)
//    {
//        try {
//            $string = "username=$username&password= [REDACTED]" . date('U') . "&auto=1";
//            $aes = new AES($this->app_key, $this->app_iv);
//            return urlencode(base64_encode($aes->encrypt($string)));
//        } catch (\Illuminate\Database\QueryException $e) {
//            $info = $e->errorInfo;
//            return response()->json([
//                'code' => $info[0],
//                'message' => $info[2]
//            ]);
//        } catch (\Exception $e) {
//            return response()->json([
//                'code' => $e->getCode(),
//                'message' => $e->getMessage()
//            ]);
//        }
//    }

    /**
     * -轉帳
     */
    public function transafer($username, $password, $type, $credit, $billNo)
    {
        try {
            $credit = ($type == 1) ? $credit : -$credit;
            // $billNo = date('His') . rand(10, 99);
            // Get Credit
            $data = array();
            $data['Method'] = 'TC';
            $data['Timestamp'] = date('U');
            $data['Username'] = $username;
            $data['Amount'] = $credit;
            $data['RequestID'] = $billNo;

            ksort($data);
            $result = $this->post($data);
            if ($result != false && is_array($result)) {
                if (isset($result['Credit'])) {
                    return true;
                } else {
                    return $result['Message'];
                }
            } else {
                return $result;
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
     * -進遊戲
     */
    public function getGameUrl($username, $game_type)
    {
        try {
            // Get Token
            $data = array();
            $data['Method'] = 'RT';
            $data['Timestamp'] = date('U');
            $data['Username'] = $username;
            ksort($data);
            $result = $this->post($data);

            if (isset($result['Token'])) {
                $url = 'http://example.invalid?token=' . $result['Token'] . '&game=' . $game_type . '&redirecrUrl=#';
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $url,
                    'url' => ['mobile' => $url, 'pc' => $url]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result['Message']
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
     * -取簽名
     */
    private function getSign($data)
    {
        $string = '';
        foreach ($data as $key => $value) {
            $string .= $key . '=' . $value . '&';
        }
        $string = substr($string, 0, -1);
        $hash_hmac = hash_hmac("sha1", $string, $this->secret, true);
        return base64_encode($hash_hmac);
    }

    private function post($data)
    {
        Common::log(0, $data, 'JOKER_post');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . '?AppID=' . $this->app_id . '&Signature=' . $this->getSign($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        // curl_setopt($ch, CURLOPT_HEADER, TRUE); // 表示需要response header
        // curl_setopt($ch, CURLOPT_NOBODY, FALSE); // 表示需要response body
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);

        // if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == '200') {
        // list ($header, $body) = explode("\r\n\r\n", response, 2);
        // }
        // echo '</br>header:' . var_dump($header);
        // echo '</br>body:' . var_dump($body);
        $result = json_decode($result, true);
        Common::log(0, ['request' => $data, 'response' => $result], 'JOKER_post');
        // 如果簽名錯誤就多取一次
        if (isset($result['Message']) && $result['Message'] == 'Invalid signature') {
            sleep(1);
            $data['Timestamp'] = date('U');
            $result = $this->post($data);
            Common::log(0, ['request' => $data, 'response' => $result], 'JOKER_post');
        }
        curl_close($ch);
        return $result;
    }
}