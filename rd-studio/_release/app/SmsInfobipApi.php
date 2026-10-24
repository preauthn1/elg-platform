<?php

namespace App;

use App\Models\SmsInfobip;
use App\Helpers\Common;
use DB;

class SmsInfobipApi
{

    public function send($mobile, $text)
    {
        try {
            $data = array();
            $url = 'sms/1/text/single';
            $data['from'] = 'InfoSMS';
            $data['to'] = $mobile;
            $data['text'] = $text;
            Common::log(0, $data, 'infobip');
            $result = $this->post($url, $data);
            Common::log(0, ['request' => $data, 'response' => $result], 'infobip');
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

    private function getSign()
    {
        $string = env('INFOBIP_USER') . ':' . env('INFOBIP_PASSWORD');
        return base64_encode($string);
    }

    private function post($url, $data)
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
            'Authorization:Basic ' . $this->getSign()
        ));
        $result = curl_exec($ch);
        Common::log(0, $result, 'infobip');
        curl_close($ch);
        return json_decode($result, true);
    }
}