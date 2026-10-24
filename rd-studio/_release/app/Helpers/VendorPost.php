<?php

namespace App\Helpers;

use App\Helpers\Common;

class VendorPost
{

    //GW99
    public static function GreatWallPost($info, $url, $data)
    {
        Common::log(0, $data, 'great_wall_' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $info->API_URL . $url);
        if (count($data) > 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'merchantName:' . $info->API_MERCHANT_NAME,
            'signature:' . VendorPost::GreatWallSign($data, $info->API_KEY),
            'Content-Type:application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'great_wall_' . $url);
        $result = json_decode($result, true);
        return $result;
    }

    private static function GreatWallSign($data, $apiKey)
    {
        $string = '';
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $string .= $key . '=' . $value . '&';
            }
        }
        $string .= 'PrivateKey=' . $apiKey;
        $hash_hmac = hash('sha256', $string, true);
        return base64_encode($hash_hmac);
    }

    //WM
    public static function WmPost($info, $url, $data, $isPost = true)
    {
        $param = '';
        $ch = curl_init();
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_POST, TRUE);
        } else {
            foreach ($data as $row) {
                $param .= '/' . $row;
            }
        }
        $post_url = $info->SERVER_NAME . $url . $info->LICENSEE_TOKEN . $param;
        Common::log(0, ['url' => $post_url, 'data' => $data], 'wm_' . $url);
        curl_setopt($ch, CURLOPT_URL, $post_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result],  'wm_' . $url);
        return json_decode($result, true);
    }
}