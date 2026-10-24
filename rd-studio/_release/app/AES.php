<?php

namespace App;

class AES
{
    /**
     * var string $method 加解密方法，可通過openssl_get_cipher_methods()獲得
     */
    protected $method;

    /**
     * var string $secret_key 加解密的金鑰
     */
    protected $secret_key;

    /**
     * var string $iv 加解密的向量，有些方法需要設定比如CBC
     */
    protected $iv;

    /**
     * var string $options （不知道怎麼解釋，目前設定為0沒什麼問題）
     */
    protected $options;

    /**
     * 建構函式
     *
     * @param string $key 金鑰
     * @param string $method 加密方式
     * @param string $iv iv向量
     * @param mixed $options 還不是很清楚 
     *
     */
    public function __construct($key = '', $method = 'AES-256-CBC', $iv = '', $options = OPENSSL_RAW_DATA)
    {
        // key是必須要設定的
        $this->secret_key = [REDACTED] != '') ? $key : env('PUSH_AES_KEY');

        $this->method = $method;

        $this->iv = ($iv != '') ? $iv : env('PUSH_AES_IV');

        $this->options = $options;
    }

    /**
     * 加密方法，對資料進行加密，返回加密後的資料
     *
     * @param string $data 要加密的資料
     * 
     * @return string 
     *
     */
    public function encrypt($data)
    {
        return bin2hex(openssl_encrypt($data, $this->method, $this->secret_key, $this->options, $this->iv));
    }

    /**
     * 解密方法，對資料進行解密，返回解密後的資料
     *
     * @param string $data 要解密的資料
     * 
     * @return string 
     *
     */
    public function decrypt($data)
    {
        return openssl_decrypt(pack("H*", $data), $this->method, $this->secret_key, $this->options, $this->iv);
    }
}