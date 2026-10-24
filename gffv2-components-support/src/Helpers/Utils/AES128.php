<?php
namespace target\support\Helpers;

/**
 * openssl 实现的 AES 加密类，支持各种 PHP 版本
 */
class AES128
{

    private $aes_key = '';

    private $aes_iv = '';

    public function __construct($aes_key, $aes_iv)
    {
        $this->aes_key = $aes_key;
        $this->aes_iv = $aes_iv;
    }

    public function encrypt($data)
    {
        // 加密
        $cryptText = openssl_encrypt($data, "aes-128-cbc", $this->aes_key, OPENSSL_RAW_DATA, $this->aes_iv);
        return base64_encode($cryptText);
    }

    public function decrypt($base64Text)
    {
        // 解密
        $cryptText = base64_decode($base64Text);
        return trim(openssl_decrypt($cryptText, 'aes-128-cbc', $this->aes_key, OPENSSL_RAW_DATA, $this->aes_iv));
    }
}
