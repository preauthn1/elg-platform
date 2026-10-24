<?php
$id = $_POST['id'];
$token = [REDACTED]'token'];

if ($id != '') {
    $data = array();
    $data['prize_member_id'] = $id;
    $result = curlPost('api/setPrize', $data, $token);
    echo json_encode($result);
}

// 加密
function encrypt($data)
{
    $cryptText = openssl_encrypt($data, "aes-256-cbc", md5(env('API_AES_KEY')), OPENSSL_RAW_DATA, env('API_IV'));
    return base64_encode($cryptText);
}

function curlPost($url, $data = '', $token)
{
    $post = array();
    $post['params'] = encrypt(json_encode($data));
    $post['key'] = md5($post['params'] . env('API_MD5_KEY'));
    $ch = curl_init();
    if ($_SERVER['HTTP_HOST'] == 'localhost') {
        curl_setopt($ch, CURLOPT_URL, 'http://localhost/frontend/public/' . $url);
    } else {
        curl_setopt($ch, CURLOPT_URL, 'https://example.invalid/' . $url);
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $header = array();
    $header[] = "Authorization: Bearer " . $token;
    $language = isset($_SESSION['language']) ? $_SESSION['language'] : 'en';
    $header[] = "Accept-Language: " . $language;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}
?>
