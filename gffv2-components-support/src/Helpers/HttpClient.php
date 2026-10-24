<?php


namespace target\support\Helpers;

class HttpClient
{
    public static function post($url, $params, $header = array(), $json = false)
    {
        $postFields = http_build_query($params);
        if ($json) {
            $header[] = 'Content-Type: application/json';
            $postFields = json_encode($params);
        }
        $postFields = $json ? json_encode($params) : http_build_query($params);

        $cha = curl_init();
        curl_setopt($cha, CURLOPT_URL, $url);
        curl_setopt($cha, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($cha, CURLOPT_POST, true);
        curl_setopt($cha, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cha, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($cha, CURLOPT_TIMEOUT, 30);
        curl_setopt($cha, CURLOPT_HTTPHEADER, $header);

        $result = curl_exec($cha);
        curl_close($cha);
        return $result;
    }

    public static function get($url, $params, $header = array())
    {
        $url = $url . '?' . http_build_query($params);

        $cha = curl_init();
        curl_setopt($cha, CURLOPT_URL, $url);
        curl_setopt($cha, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cha, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($cha, CURLOPT_TIMEOUT, 30);
        curl_setopt($cha, CURLOPT_HTTPHEADER, $header);

        $result = curl_exec($cha);
        curl_close($cha);
        return $result;
    }

    public static function curlString($method, $url, $post, $header)
    {
        $headers = array();
        foreach ($header as $val) {
            $headers[] = "-H \"$val\"";
        }
        $optHeader = implode(" ", $headers);
        $optMethod = sprintf("-X %s", $method);
        $data = sprintf("-d \"%s\"", $post);
        return sprintf("curl %s %s %s %s", $optMethod, $optHeader, $data, $url);
    }
}
