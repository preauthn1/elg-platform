<?php
namespace App;

use DB;

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
                if (isset($result->code) && $result->code == 0) {
                    return [
                        'code' => 1000,
                        'msg' => $result->data->url
                    ];
                } else {
                    return [
                        'code' => $result->code,
                        'msg' => $result->msg
                    ];
                }
            } else {
                return [
                    'code' => 9999,
                    'msg' => 'unknow'
                ];
            }
            return $this->post($url, $data);
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
        $string = http_build_query($data) . '&key=' . env("YXF_KEY");
        return md5($string);
    }

    private function post($url, $data)
    {
        $data['sign'] = $this->getSign($data);
        $data['signType'] = 'MD5';
        $this->log($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env("YXF_URL") . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        $this->log($result);
        return $this->unicode_decode($result);
    }

    private function unicode_decode($str)
    {
        if (! $str)
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

    private function log($content)
    {
        if (is_array($content)) {
            unset($content['password']);
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        DB::table('access_log_frontend_' . date('Ym'))->insert(array(
            'user' => 0,
            'content' => $content,
            'action' => 'YXF',
            'ip' => '0.0.0.0'
        ));
    }
}
