<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class YXFController extends Controller
{

    public function alipay(Request $request)
    {
        try {
            $url = 'api/deposit/alipay/qrcode';
            $data = array();
            $data['merchantName'] = env("YXF_MERCHANT");
            $data['orderId'] = date('UYMD');
            $data['amount'] = 100;
            $data['noticeUrl'] = 'https//' . $_SERVER['HTTP_HOST'] . '/api/callback';
            ksort($data);
            $data['sign'] = $this->getSign($data);
            $data['signType'] = 'MD5';
            var_dump($data);
            var_dump($this->post($url, $data));
            exit();
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

    public function unionPay(Request $request)
    {
        try {
            $url = 'api/deposit/unionPayH5';
            $data = array();
            $data['merchantName'] = env("YXF_MERCHANT");
            $data['orderId'] = date('UYMD');
            $data['amount'] = 100;
            ksort($data);
            $data['sign'] = $this->getSign($data);
            $data['signType'] = 'MD5';
            var_dump($data);
            var_dump($this->post($url, $data));
            exit();
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
        $string = '';
        foreach ($data as $key => $value) {
            $string .= $key . '=' . $value . '&';
        }
        $string .= 'key=' . env("YXF_KEY");
        $string = http_build_query($data) . '&key=' . env("YXF_KEY");
        return md5($string);
    }

    private function post($url, $data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env("YXF_URL") . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
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
}
