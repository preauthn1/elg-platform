<?php

namespace App;

use DB;
use App\Models\Yxf;
use App\Helpers\Common;

class PaymentYXF
{

    // url會驗簽失敗
    public function deposit($orderId, $amount, $way)
    {
        try {
            $data = array();
            switch ($way) {
                case 'aliPay':
                    $param = 'alipay/qrcode';
                    break;
                case 'unionPay':
                    $param = 'unionPayH5';
                    // $data['returnUrl'] = env('APP_URL') . 'success';
                    break;
            }
            $url = 'api/deposit/' . $param;
            $data['merchantName'] = env("YXF_MERCHANT");
            $data['orderId'] = $orderId;
            $data['amount'] = $amount;
            // $data['noticeUrl'] = 'https://' . $_SERVER['HTTP_HOST'] . '/api/callback';
            ksort($data);
            $result = $this->post($url, $data);
            if (is_object($result)) {
                $data = array();
                $data['order_id'] = substr($orderId, -7);
                $data['api_amount'] = $amount;
                $data['remark'] = $result->msg;
                $data['status'] = 0;
                $data['admin'] = 0;
                if (isset($result->code) && $result->code == 0) {
                    $data['real_amount'] = $result->data->amount;
                    $data['url'] = $result->data->url;
                    $data['state'] = $result->data->state;
                    if (isset($result->data->bank_user_name)) {
                        $data['bank_user_name'] = $result->data->bank_user_name;
                        $data['bank_card_id'] = $result->data->bank_card_id;
                    }
                    $rs = [
                        'code' => 1000,
                        'msg' => $result->data->url
                    ];
                } else {
                    $rs = [
                        'code' => $result->code,
                        'msg' => $result->msg
                    ];
                }
                Yxf::create($data);
            } else {
                $rs = [
                    'code' => 9999,
                    'msg' => 'unknow'
                ];
            }
            return $rs;
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

    private function getSign($data)
    {
        $string = '';
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $string .= $key . '=' . $value . '&';
            }
        }
        $string .= 'key=' . env('YXF_KEY');
        return md5($string);
    }

    private function post($url, $data)
    {
        $data['sign'] = $this->getSign($data);
        $data['signType'] = 'MD5';
        Common::log(0, $data,  'YXF_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env("YXF_URL") . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result],  'YXF_' . $url);
        return $this->unicode_decode($result);
    }

    private function unicode_decode($str)
    {
        if (!$str)
            return $str;
        $decode = json_decode($str);
        if ($decode)
            return $decode;
        $str = '["' . $str . '"]';
        $decode = json_decode($str);
        if (count($decode) == 1) {
            return $decode[0];
        }
        return $str;
    }
}