<?php

namespace Tests\Unit;

use App\Helpers\VendorSend;
use Tests\TestCase;

class USDTTest extends TestCase
{
    private $token = [REDACTED]";

    //private $api_url = "https://example.invalid/";
    private $api_url = "http://0.0.0.0:8000/";

//    private $ext_api_url = "https://example.invalid/";
    private $ext_api_url = "http://0.0.0.0:8000/";

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

        $sender = new VendorSend(['json_post'=>true]);
        $response = $sender->send("test", $url, "POST", $postData, [
            "Content-Type: application/json;charset=UTF-8",
            "Accept-Language: zh-tw",
            "Authorization: Bearer ".$this->token,
        ]);
        var_dump($response);
        $response = json_decode($response, true);
        $this->assertEquals($response['code'], 1000);
    }

    public function testOrder()
    {
        $key = env('API_AES_KEY');
        $iv = env('API_IV');
        $md5 = env('API_MD5_KEY');
        $aes = new AES($key, $iv);
        $data = ["order_type_id"=>8, 'amount'=>1];

        $params = $aes->encrypt(json_encode($data));
        $key = md5($params . $md5);
        $postData = [
            'params' => $params,
            'key' => $key
        ];
        $url = $this->api_url . "api/deposit/order";

        $sender = new VendorSend(['json_post'=>true]);
        $response = $sender->send("test", $url, "POST", $postData, [
            "Content-Type: application/json;charset=UTF-8",
            "Accept-Language: zh-tw",
            "Authorization: Bearer ".$this->token,
        ]);
        var_dump($response);
        $response = json_decode($response, true);
        $this->assertEquals($response['code'], 1000);
    }

    public function testUSDTExternalCallback()
    {
        $merchantID = 'coinsandbox_demo1';
        $secretKey = '7764k99jhg9as21506c840e1asa3degf';
        $notifyTime = date('YmdHis');

        $signdata = $data = [
            "crypto"=>'EUSDT',
            'amount'=>'1.00',
            'item_number'=>'202009041091406659',
            'status'=>2,
            'merchant_id'=>$merchantID,
        ];
        $signdata['amount'] = $signdata['amount'] * 1000000;

        ksort($signdata);
        //var_dump(array_values($data));
        $sign = strtolower(sha1(join("", array_values($signdata)) . $secretKey));
        $data['wallet_address'] = "wallet_address";
        $data['sign'] = $sign;

        $url = $this->ext_api_url . "api/cashflow/1";

        $sender = new VendorSend(['json_post'=>false]);
        $response = $sender->send("test", $url, "POST", $data);
        var_dump($response);
        //$response = json_decode($response, true);
        $this->assertEquals($response, 'success');
    }



}
