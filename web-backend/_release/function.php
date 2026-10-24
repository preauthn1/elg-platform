<?php

function isLogin()
{
    if (isset($_SESSION['token']) && $_SESSION['expire'] > date('U')) {
        return true;
    } else {
        return false;
    }
}

// 加密
function encrypt($data)
{
    $cryptText = openssl_encrypt($data, "aes-256-cbc", md5(AES_KEY), OPENSSL_RAW_DATA, AES_VI);
    return base64_encode($cryptText);
}

function curlPost($url, $data = '')
{
    $post = array();
    $post['params'] = encrypt(json_encode($data));
    $post['key'] = md5($post['params'] . MD5_KEY);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, API_DOMAIN . $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $header = array();
    if (isset($_SESSION['token'])) {
        $header[] = "Authorization: Bearer " . $_SESSION['token'];
    }
    $language = isset($_SESSION['language']) ? $_SESSION['language'] : DEFAULT_LANGUAGE;
    $header[] = "Accept-Language: " . $language;
    // $header[] = "Accept-Language: " . isset($_SESSION['language']) ? $_SESSION['language'] : DEFAULT_LANGUAGE;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

?>