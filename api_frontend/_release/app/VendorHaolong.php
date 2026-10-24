<?php

namespace App;

use App\Helpers\Common;
use App\Models\HaolongAccount;
use DB;

class VendorHaolong extends Vendor
{
    private $api_url;
    private $api_html5_url;
    private $api_user;
    private $api_password;
    private $user;
    private $password;
    private $use_prefix;

    public function __construct($info)
    {
        $this->name = 'Haolong';
        $this->vendor_id = 4;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;

        $this->api_url = $info->API_URL;
        $this->api_html5_url = $info->API_HTML5_URL;
        $this->api_user = $info->API_USER;
        $this->api_password = [REDACTED];
        $this->user = $info->HAOLONG_USER;
        $this->password = [REDACTED];
        $this->use_prefix = isset($info->USE_PREFIX) ? $info->USE_PREFIX : 1; //預設使用
    }

    /**
     * -取餘額
     * }
     */
    public function getBalance($username, $password = '')
    {
        try {
            // 取用戶資料
            $url = 'getProfile.aspx';
            $data = array();
            if ($this->use_prefix == 1) {
                $data['loginID'] = $this->user . $username;
            } else {
                $data['loginID'] = $username;
            }
            $result = $this->post($url, $data, $username);
            if ($result != false) {
                if ($result->errorCode == 0) {
                    $log_data = array(
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => (string) $result->balance
                    );
                } else {
                    $log_data = array(
                        'code' => 9996,
                        'message' => $result->desc
                    );
                }
            } else {
                $log_data = array(
                    'code' => 9995,
                    'message' => __('error_message.9995')
                );
            }

            return $log_data;
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
     * -取餘額
     * }
     */
    public function changePassword($username, $password)
    {
        try {
            // 修改密碼
            $url = 'changePasswordStatus.aspx';
            $data = array();
            if ($this->use_prefix == 1) {
                $data['loginID'] = $this->user . $username;
            } else {
                $data['loginID'] = $username;
            }
            $data['newpass'] = $password;
            $result = $this->post($url, $data, $username);
            if ($result != false) {
                if ($result->errorCode == 0) {
                    $log_data = array(
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => (string) $result->balance
                    );
                } else {
                    $log_data = array(
                        'code' => 9996,
                        'message' => $result->desc
                    );
                }
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
     * -進入投注廳
     */
    public function getGameUrl($username, $password)
    {
        try {
            // 取SESSION、TOKEN
            $url = 'betLogin.aspx';
            $data = array();
            if ($this->use_prefix == 1) {
                $data['user'] = $this->user . $username;
            } else {
                $data['user'] = $username;
            }
            $data['pass'] = $password;
            $login_info = $this->post($url, $data, $username);
            if (isset($login_info->sessionID)) {
                $url = $this->api_html5_url . '?user=' . $data['user'] . '&sessionID=' . $login_info->sessionID . '&tokenCode=' . $login_info->tokenCode;
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $url,
                    'url' => ['mobile' => $url, 'pc' => $url]
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $login_info
                ];
            }
            // 投注廳URL
            return $this->api_url . 'bet.aspx?user=' . $data['user'] . '&lang=en-us&sessionID=' . $login_info->sessionID . '&tokenCode=' . $login_info->tokenCode;
            // return $this->post($url, $data);
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
     * -轉帳
     */
    public function transafer($username, $password, $type, $credit, $billNo)
    {
        try {
            $url = ($type == 1) ? 'deposit.aspx' : 'withdraw.aspx';
            // Get Credit
            $data = array();
            if ($this->use_prefix == 1) {
                $data['loginID'] = $this->user . $username;
            } else {
                $data['loginID'] = $username;
            }
            $data['amount'] = $credit;

            $result = $this->post($url, $data, $username);
            if ($result != false && is_object($result)) {
                if ($result->errorCode == 0) {
                    return true;
                } else {
                    return (string) $result->desc;
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

    private function post($url, $data, $vendorUsername)
    {
        $data['apiUser'] = $this->api_user;
        $data['apiPass'] = $this->api_password;

        if (!isset($data['user'])) {
            $account = HaolongAccount::where('username', $vendorUsername)->first();
            if (is_object($account) && $account->is_vworld == 1) {
                // vworld 舊帳號有代理階層
                $agent = DB::table('members as m')
                    ->leftJoin('agents as a', 'a.id', 'm.parent')
                    ->where('m.id', $account->member_id)
                    ->select('a.username', 'a.im_certify')
                    ->first();
                if (is_object($agent)) {
                    $data['user'] = $this->user . $agent->username;
                    $data['pass'] = $agent->im_certify;
                }
            } else {
                $data['user'] = $this->user;
                $data['pass'] = $this->password;
            }
        }
        Common::log(0, $data, '豪龍_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], '豪龍_' . $url);
        return simplexml_load_string($result);
    }
}