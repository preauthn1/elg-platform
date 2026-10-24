<?php

namespace App;

use App\Helpers\Common;

class Vendor
{
    protected $name;
    protected $rate;            //轉換比例
    protected $transfer_min_amount = 0.01;    //最小轉帳金額(以系統幣值計算)
    protected $vendor_id;

    public function getProperties() {
        return [
            'rate' => $this->rate,
            'transfer_min_amount'  => $this->transfer_min_amount,
        ];
    }
    public function getName()
    {
        return $this->name;
    }

    public function getRate()
    {
        return $this->rate;
    }

    //最小轉帳金額
    public function getTransferMinAmount($point)
    {
        $point = strval($point * 10000);
        $transfer_min_amount = strval($this->transfer_min_amount * 10000);
        return strval(($point - ($point % $transfer_min_amount)) / 10000);
    }
	
    /**
     * -AES加密
     */
    private function encrypt($params)
    {
        $params = json_encode($params);
        $cryptText = openssl_encrypt($params, "aes-256-cbc", $this->api_key, OPENSSL_RAW_DATA, $this->api_iv);
        $data = base64_encode($cryptText);
        return $data;
    }
	
    /**
     * -AES解密
     */
    public function decrypt($params)
    {
        $params = base64_decode($params);
        $cryptText = openssl_decrypt($params, "aes-256-cbc", $this->api_key, OPENSSL_RAW_DATA, $this->api_iv);
        return json_decode($cryptText);
    }
}
