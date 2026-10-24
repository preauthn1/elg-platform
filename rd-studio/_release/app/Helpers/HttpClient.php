<?php


namespace App\Helpers;


class HttpClient
{
    static public function post($url, $params, $header=array(), $json=false) {
        //dd($url);
        //dd($params);
        //echo http_build_query($params);
        $postFields = $json?json_encode($params):http_build_query($params);
        //echo HttpClient::curlString("post", $url, $postFields, $header) . "\n";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    static public function curlString($method, $url, $post, $header) {
        $h = array();
        foreach($header as $k => $v) {
            $h[] = "-H \"$v\"";
        }
        $optHeader = implode(" ", $h);
        $optMethod = sprintf("-X %s", $method);
        $data = sprintf("-d \"%s\"", $post);
        return sprintf("curl %s %s %s %s", $optMethod, $optHeader, $data, $url);
    }
}