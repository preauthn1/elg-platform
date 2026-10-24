<?php

namespace Tests\Unit;

use App\Helpers\VendorSend;
use target\support\Helpers\HttpClient;
use Tests\TestCase;
use Tests\AES;

class ExampleTest extends TestCase
{
    private $token = [REDACTED]";

    //private $api_url = "https://example.invalid/";
    private $api_url = "http://0.0.0.0:8000/";

    private $ext_api_url = "https://example.invalid/";
//    private $ext_api_url = "http://0.0.0.0:8000/";

    /**
     * A basic test example.
     *
     * @return void
     */
    public function testBasicTest()
    {
        $this->assertTrue(true);
    }

    public function testGetOrderType()
    {
        $key = env('API_AES_KEY');
        $iv = env('API_IV');
        $md5 = env('API_MD5_KEY');
        $aes = new AES($key, $iv);
        $data = [];

        $params = $aes->encrypt(json_encode($data));
        $key = md5($params . $md5);
        $postData = [
            'params' => $params,
            'key' => $key
        ];
        $url = $this->api_url . "api/deposit/getOrderType";
        $client = new HttpClient();
        $body = $client->request(
            "test",
            "POST",
            $url,
            [
                "json" => $postData,
                "headers" => [
                    "Accept-Language" => "zh-tw",
                    "Authorization" => "Bearer " . $this->token,
                ]
            ]
        );

        var_dump($body);
        $response = json_decode($body, true);
        $this->assertEquals($response['code'], 1000);
    }

    public function testOrder()
    {
        $key = env('API_AES_KEY');
        $iv = env('API_IV');
        $md5 = env('API_MD5_KEY');
        $aes = new AES($key, $iv);
        $data = ["order_type_id"=>3, 'amount'=>1];

        $params = $aes->encrypt(json_encode($data));
        $key = md5($params . $md5);
        $postData = [
            'params' => $params,
            'key' => $key
        ];
        $url = $this->api_url . "api/deposit/order";
        $client = new HttpClient();
        $body = $client->request(
            "test",
            "POST",
            $url,
            [
                "json" => $postData,
                "headers" => [
                    "Accept-Language" => "zh-tw",
                    "Authorization" => "Bearer " . $this->token,
                ]
            ]
        );
        var_dump($body);
        $response = json_decode($body, true);
        $this->assertEquals($response['code'], 1000);
    }

    public function testExternalCallback()
    {
        // Allpay
        //$merchantNo = 'SPG008271';
        //$merchantKey = '0bee7414a1cf22fdec676b92ec5ae890';
        // marscard
        $merchantNo = 'GBP009527';
        $merchantKey = '93485df2a9e1bcdd32522eac44b079d4';
        $notifyTime = date('YmdHis');
        $data = [
            "service"=>"QRH5",
            "merchantNo"=>$merchantNo,
            'paymentCode'=>'MARSCARD',
            'bankCode'=>'Notsupport',
            'status'=>'SUCCESS',
            'amount'=>'150.00',
            'orderNo'=>'202010211902176743',
            'gbpOrderNo'=>'QRHA20201021024310000161064109',
            'createTime'=>$notifyTime
        ];
        $content = base64_encode(json_encode($data));
        $msg = sprintf('content:%s&notifyTime:%s&version:%s&key:%s', $content, $notifyTime, '1.0', $merchantKey);
        $signMsg = strtoupper(md5($msg));
/*
        $notifyTime = '20200902125149';
        $signMsg = 'E35551E68370C55483DA41B74B0995FB';
        $content = 'eyJzZXJ2aWNlIjoiUVJINSIsIm1lcmNoYW50Tm8iOiJTUEcwMDgyNzEiLCJwYXltZW50Q29kZSI6IjMzIiwiYmFua0NvZGUiOiJOb3RzdXBwb3J0Iiwic3RhdHVzIjoiU1VDQ0VTUyIsImFtb3VudCI6IjEwMC4wMCIsIm9yZGVyTm8iOiIyMDIwMDkwMjg4ODQ5MjY3MDciLCJnYnBPcmRlck5vIjoiUVJIQTIwMjAwOTAyMDQxMTA5MDAwNjk0NTYzMzQ3IiwiY3JlYXRlVGltZSI6IjIwMjAwOTAyMTI1MTQ5In0=';
*/
        //echo base64_decode($content);
        $post = [
            'signMsg' => $signMsg,
            'version' => '1.0',
            'notifyTime' => $notifyTime,
            'content' => $content
        ];

        $url = "https://example.invalid/api/cashflow/4";

        $client = new HttpClient();
        $body = $client->request(
            "test",
            "POST",
            $url,
            [
                "json" => $post,
                "headers" => [
                    "Accept-Language" => "zh-tw",
                    "Authorization" => "Bearer " . $this->token,
                ]
            ]
        );

        var_dump($body);
        $response = json_decode($body, true);
        $this->assertEquals($response, 'OK');
    }


    public function testNCPayExternalCallback()
    {

        //{"APIUrl":"http:\/\/api.ncpay.cash\/","APIMerchant":"VWOULD","APIKey":"C6U2IDUUPWHLRCPO46T6","APISecret":"SKSJS4WJSQUAPRYMZ6IBUAZEZPBDZYILLPVRZA4O"}
        $APIKey = [REDACTED]';
        $data = [
            'apikey' => "iVu1aV9t5vC28I9Sfd2q",
            'tobank' => "mbb",
            'data' => [
                'type' => "4",
                'amount' => "1",
                'result' => "fail",
                'thirdpartyid' => "202009033512894592",
            ],
            'type' => "4",
            'amount' => "1.00",
            'timestamp' => "1599128764",
            'action' => "orderfail",
            'event' => "deposit_callback",
            'toaccount' => null,
            'orderid' => "202009033512894592",
        ];
        $x = rand(10000, 99999) . rand(10000, 99999);
        $sign = sha1($x . json_encode($data) . 'E5IsFhdRckhD8F09MFD7XBrUj3q93dqoSPn8RuG5');

        $url = $this->ext_api_url . "api/cashflow/2?sign=".$sign."&user=".$x;

        $sender = new VendorSend(['json_post'=>true]);
        $response = $sender->send("test", $url, "POST", $data, [
            "Content-Type: application/json",
            "Accept-Language: zh-tw",
        ]);
        var_dump($response);
        $response = json_decode($response, true);
        $this->assertEquals($response['status'], 'ok');
    }

}
