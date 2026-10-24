<?php

namespace Tests\Unit;

use App\Helpers\VendorSend;
use Tests\AES;
use Tests\TestCase;

class GameApiTest extends TestCase
{
    private $token = [REDACTED]";

    //private $api_url = "https://example.invalid/";
    private $api_url = "http://0.0.0.0:8000/";
    public function testGetGameUrl()
    {
        $key = env('API_AES_KEY');
        $iv = env('API_IV');
        $md5 = env('API_MD5_KEY');
        $aes = new AES($key, $iv);
        $data = ['game_id' => 2123];

        $params = $aes->encrypt(json_encode($data));
        $key = md5($params . $md5);
        $postData = [
            'params' => $params,
            'key' => $key
        ];
        $url = $this->api_url . "api/getGameUrl";
        echo $url . PHP_EOL;
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

    public function testSingleWalletBalance()
    {
        $key = env('API_AES_KEY');
        $iv = env('API_IV');
        $md5 = env('API_MD5_KEY');
        $aes = new AES($key, $iv);
        $data = ['vendor_id' => 27];

        $params = $aes->encrypt(json_encode($data));
        $key = md5($params . $md5);
        $postData = [
            'params' => $params,
            'key' => $key
        ];
        $url = $this->api_url . "api/singleWalletBalance";
        echo $url . PHP_EOL;
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

}
