<?php
namespace App;

/**
 * openssl 实现的 AES 加密类，支持各种 PHP 版本
 */
class AES
{

    private $md5Key = '';

    private $iv = '';

    public function __construct($key, $iv)
    {
        $this->md5Key = md5($key);
        $this->iv = $iv;
    }

    public function encrypt($data)
    {
        // 加密
        $cryptText = openssl_encrypt($data, "aes-256-cbc", $this->md5Key, OPENSSL_RAW_DATA, $this->iv);
        return base64_encode($cryptText);
    }

    public function decrypt($data)
    {
        // 解密
        $cryptText = base64_decode($data);
        return trim(openssl_decrypt($cryptText, 'aes-256-cbc', $this->md5Key, OPENSSL_RAW_DATA, $this->iv));
    }
}