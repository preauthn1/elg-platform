<?php

namespace App;

use App\Helpers\Common;
use App\Setting;
use App\Member;

class ThirdParty
{

    private $agent_id = '';

    public function __construct($agentId)
    {
        $this->agent_id = $agentId;
    }

    /**
     * -取餘額
     */
    public function getInfo($memberId)
    {
        try {
            $third_party_id = $this->getThirdPartyId($memberId);
            if ($third_party_id != '') {
                $data = array();
                $data['username'] = $third_party_id;
                $result = $this->post($data, 'getInfo');
                if ($result['code'] == 1000) {
                    return $result['data'];
                } else {
                    return -1;
                }
            } else {
                return -1;
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
     * - 確認密碼
     */
    public function checkPassword($memberId, $password)
    {
        try {
            $third_party_id = $this->getThirdPartyId($memberId);
            if ($third_party_id != '') {
                $data = array();
                $data['username'] = $third_party_id;
                $data['password'] = $password;
                $result = $this->post($data, 'checkPassword');
                if ($result['code'] == 1000) {
                    return true;
                } else {
                    return false;
                }
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

    /**
     * -轉帳
     */
    public function transaction($memberId, $platform, $amount, $transactionType, $remark = '', $operatorId = 0, $userId = 0)
    {
        try {
            $third_party_id = $this->getThirdPartyId($memberId);
            if ($operatorId != 0) {
                //玩家自己操作的，所以agent取他自己的
                $operator = $this->getThirdPartyId($operatorId);
            } else {
                //代理操作的，有可能是子帳號
                $operator = $this->getCustomerUsername($userId);
            }
            if ($third_party_id != '') {
                $agent = User::find($this->agent_id);
                $data = array();
                $data['agent_username'] = $agent->username;
                $data['username'] = $third_party_id;
                $data['platform'] = $platform;
                $data['amount'] = $amount;
                $data['transaction_type'] = $transactionType;
                $data['operator'] = $operator;
                $data['remark'] = $remark;
                $result = $this->post($data, 'transaction');
                if ($result['code'] == 1000) {
                    return true;
                } else {
                    return false;
                }
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

    /**
     * -代理之間轉帳
     */
    public function agentTransaction($log)
    {
        try {
            $operator = $this->getCustomerUsername($log['from']);
            if ($operator != '') {
                $from_agent = User::find($this->agent_id);
                $to_agent = User::find($log['to']);
                $data = array();
                $data['from'] = $from_agent->username;
                $data['to'] = $to_agent->username;
                $data['type'] = $log['type'];
                $data['amount'] = $log['amount'];
                $data['from_limit_of_credit'] = $log['from_limit_of_credit'];
                $data['from_used_credit'] = $log['from_used_credit'];
                $data['to_limit_of_credit'] = $log['to_limit_of_credit'];
                $data['to_used_credit'] = $log['to_used_credit'];
                $data['operator'] = $operator;
                $data['status'] = $log['status'];
                $data['remark'] = $log['remark'];
                $result = $this->post($data, 'agentTransaction');
                if ($result['code'] == 1000) {
                    return true;
                } else {
                    return false;
                }
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

    private function getThirdPartyId($memberId)
    {
        $member = Member::find($memberId);
        if (is_object($member)) {
            return $member->third_party_id;
        } else {
            return 0;
        }
    }

    private function getCustomerUsername($userId)
    {
        $row = User::find($userId);
        if (is_object($row)) {
            return $row->username;
        } else {
            return 0;
        }
    }

    /**
     * -加密
     */
    private function encrypt($data)
    {
        $cryptText = openssl_encrypt($data, "aes-256-cbc", md5(env('THIRD_PARTY_AES_KEY')), OPENSSL_RAW_DATA, env('THIRD_PARTY_IV'));
        return base64_encode($cryptText);
    }

    private function post($data, $url)
    {
        Common::log($this->agent_id, $data, $url . '_post');
        $params = $this->encrypt(json_encode($data));
        $key = md5($params . env('THIRD_PARTY_MD5_KEY'));
        $data = [
            'params' => $params,
            'key' => $key
        ];
        // 取API URL
        $setting = Setting::where('agent_id', $this->agent_id)->first();
        if (is_object($setting) && $setting->api_url != '') {
            // 如果有取到網址
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $setting->api_url . $url);
            Common::log($this->agent_id, $setting->api_url . $url, $url . '_post');
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $result = curl_exec($ch);
            Common::log($this->agent_id, $result, $url . '_post');
            curl_close($ch);
            return json_decode($result, true);
        } else {
            Common::log($this->agent_id, '查無url', $url . '_post');
            return [
                'code' => 9999,
                'message' => '資料錯誤'
            ];
        }
    }
}