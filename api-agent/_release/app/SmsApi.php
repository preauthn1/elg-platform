<?php

namespace App;

use DB;
use App\SmsInfobip;
use App\SmsSmsGet;
use App\Helpers\Common;

class SmsApi
{
    /* 使用簡訊商Infobip發送簡訊 */
    public function sendByInfobip($memberId, $mobile, $text)
    {
        try {
            $data = array();
            $url = 'sms/1/text/single';
            $data['from'] = 'InfoSMS';
            $data['to'] = $mobile;
            $data['text'] = '+' . $text;
            $result = $this->postToInfobip($url, $data);
            if (is_array($result)) {
                $data = array();
                $data['member_id'] = $memberId;
                $data['mobile'] = $mobile;
                $data['text'] = $text;
                $data['status'] = 0;
                if (isset($result['messages'])) {
                    $row = $result['messages'][0];
                    $data['smsCount'] = $row['smsCount'];
                    $data['message_id'] = $row['messageId'];
                    $data['status_group_id'] = $row['status']['groupId'];
                    $data['status_group_name'] = $row['status']['groupName'];
                    $data['status_id'] = $row['status']['id'];
                    $data['status_name'] = $row['status']['name'];
                    $data['status_description'] = $row['status']['description'];
                    $rs = [
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ];
                } else {
                    $rs = [
                        'code' => $result->code,
                        'message' => $result->msg
                    ];
                }
                SmsInfobip::create($data);
            } else {
                $rs = [
                    'code' => 9999,
                    'message' => 'unknow'
                ];
            }
            return $rs;
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

    private function getInfobipSign()
    {
        $string = env('INFOBIP_USER') . ':' . env('INFOBIP_PASSWORD');
        return base64_encode($string);
    }

    private function postToInfobip($url, $data)
    {
        Common::log(0, $data, 'infobip');
        $data_string = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('INFOBIP_URL') . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization:Basic ' . $this->getInfobipSign()
        ));
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'infobip');
        curl_close($ch);
        return json_decode($result, true);
    }

    /* 使用簡訊商SMS-Get發送簡訊 */
    public function sendBySmsGet($memberId, $mobile, $text)
    {
        try {
            $data = array();
            $url = 'api_send.php';
            $data['method'] = 1; //1:立即發送，2:預約發送
            $data['sms_msg'] = '++' . $text;
            $data['phone'] = $mobile;
            $result = $this->postToSmsGet($url, $data);
            if (is_array($result) && $result['stats'] == 'True') {
                $data = array();
                $data['member_id'] = $memberId;
                $data['mobile'] = $mobile;
                $data['text'] = $text;
                $data['stats'] = $result['stats'];
                $data['error_code'] = $result['error_code'];
                $data['error_msg'] = $result['error_msg'];
                SmsSmsGet::create($data);
                $rs = [
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ];
            } else {
                $rs = [
                    'code' => 9999,
                    'message' => 'unknow'
                ];
            }
            return $rs;
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

    private function postToSmsGet($url, $data)
    {
        $data['username'] = env('SMS_GET_USERNAME');
        $data['password'] = env('SMS_GET_PASSWORD');

        Common::log(0, $data, 'sms-get');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SMS_GET_HOST') . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'sms-get');
        curl_close($ch);
        return json_decode($result, true);
    }

    /* 使用簡訊商SMS-WORKS發送簡訊 */
    public function sendBySmsWorks($memberId, $mobile, $text)
    {
        try {
            $data = array();
            $url = 'ReceiveSMS.aspx';
            $data['ContentType'] = 1; //1:ASCII text, 2:UCS formatted
            $data['MsgText'] = '+++' . $text;
            $data['MobileNumber'] = $mobile;
            $result = $this->postToSmsWorks($url, $data);
            if (is_array($result)) {
                $data = array();
                $data['member_id'] = $memberId;
                $data['mobile'] = $mobile;
                $data['text'] = $text;
                $data['status'] = $result[0];
                $data['transation_id'] = $result[1];
                $data['response_message'] = json_encode($result);
                SmsSmsWork::create($data);
                $rs = [
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ];
            } else {
                $rs = [
                    'code' => 9999,
                    'message' => 'unknow'
                ];
            }
            return $rs;
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

    private function postToSmsWorks($url, $data)
    {
        $data['Username'] = env('SMS_WORKS_USERNAME');
        $data['Password'] = env('SMS_WORKS_PASSWORD');

        Common::log(0, $data, 'sms-works');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SMS_WORKS_HOST') . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'sms-works');
        curl_close($ch);
        return explode(':', $result);
    }
}