<?php

namespace App\Helpers;

class CommonHelper
{
    public static function getRealIp(): string
    {
        if (isset($_SERVER['HTTP_ALI_CDN_REAL_IP'])) {
            $ip = $_SERVER['HTTP_ALI_CDN_REAL_IP'];
        } elseif (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty(request()->header('V-HTTP-CLIENT-IP'))) {
            $ip = request()->header('V-HTTP-CLIENT-IP');
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // HTTP_X_FORWARDED_FOR may contain multiple IP addresses separated by commas
            // The first one is the original client IP, the rest are proxy servers
            $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ipList[0]);
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return (string) $ip;
    }
}
