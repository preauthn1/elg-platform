<?php
namespace App;

/**
 * 通用的Function
 */
class General
{

    public function encrypt($key, $iv, $data)
    {
        // openssl 實現的 AES 加密
        $cryptText = openssl_encrypt($data, "aes-256-cbc", $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($cryptText);
    }

    public function decrypt($key, $iv, $data)
    {
        // openssl 實現的 AES 解密
        $cryptText = base64_decode($data);
        return trim(openssl_decrypt($cryptText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
    }

    public function getLanguageId($code)
    {
        $row = Language::where('code', $code)->where('status', 1)->first();
        return is_object($row) ? $row->id : 1;
    }

    public function getIp()
    {
        if (! empty($_SERVER["HTTP_CLIENT_IP"])) {
            $ip = $_SERVER["HTTP_CLIENT_IP"];
        } elseif (! empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"];
        }
        return $ip;
    }

    public function log($userId, $content, $action)
    {
        unset($content['password']);
        unset($content['password_confirmation']);
        unset($content['new_password']);
        unset($content['new_password_confirmation']);
        DB::table('access_log_frontend_' . date('Ym'))->insert(array(
            'user' => $userId,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
            'action' => $action,
            'ip' => $this->getIp()
        ));
    }
}