<?php

function isLogin()
{
    if (isset($_SESSION['action']) && $_SESSION['action'] == 'auth') {
        return true;
    } else if (isset($_SESSION['token']) && $_SESSION['expire'] > date('U')) {
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

// 解密
function decrypt($data)
{
    $cryptText = base64_decode($data);
    return trim(openssl_decrypt($cryptText, 'aes-256-cbc', md5(AES_KEY), OPENSSL_RAW_DATA, AES_VI));
}

function display_error_msg_html($text, $isIncludeJs = true)
{
    $file_path = "../_footer.php";

    if ($isIncludeJs && file_exists($file_path)) {
        //需加這段 不然menu的click事件會壞掉
        require_once $file_path;
    }
    
    $icon = "<i class='fa fa-exclamation-circle'></i>";
    $innerDiv = "<div class='default_error_msg'>".$icon. $text."</div>";
    $html = "<div class='page-container'>".$innerDiv."</div>";
    return $html;
}

function IsQueryStringExist($key)
{
    return isset($_GET[$key]) && trim($_GET[$key]) != "";
}

function IsNullOrEmptyString($str)
{
    return (!isset($str) || trim($str) === '');
}

function check_permission_menuCode($menuCode)
{
    $permission_query_data = array();
    $permission_query_data['agent_id'] = $_SESSION['id'];
    $permission_query_data['menu_code'] = $menuCode;

    $permission_query = curlPost('api/agentPermission/check', $permission_query_data);
    $is_menu_exist = false;

    if ($permission_query['code'] == 1000) {
        $is_menu_exist = $permission_query["data"]["is_exist"];
    }
    
    return $is_menu_exist;
}

function getCustomizedSetting($setting, $key)
{
    foreach ($setting['data'] as $row) {
        if ($row['setting_key'] == $key) {
            return $row['value'];
        }
    }
}

function curlPost($url, $data = '', $timeout = 60, $domain_name = '')
{
    $post = array();
    $post['params'] = encrypt(json_encode($data));
    $post['key'] = md5($post['params'] . MD5_KEY);
    $domain = API_DOMAIN;
    if (strtolower(trim($domain_name)) == 'internal') {
        $domain = API_INTERNAL;
        $post = $data;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $domain . $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $header = array();
    if (isset($_SESSION['token'])) {
        $header[] = "Authorization: Bearer " . $_SESSION['token'];
    }
    if (isset($_COOKIE['language'])) {
        $language = $_COOKIE['language'];
    } elseif (isset($_SESSION['language'])) {
        $language = $_SESSION['language'];
    } else {
        $language = DEFAULT_LANGUAGE;
    }
    $header[] = "Accept-Language: " . $language;
    $header[] = "V-HTTP-CLIENT-IP: " . getIp();
    // $header[] = "Accept-Language: " . isset($_SESSION['language']) ? $_SESSION['language'] : DEFAULT_LANGUAGE;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

function curlPostWithoutEncrypt($url, $data = '')
{
    $post = $data;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, API_DOMAIN . $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $header = array();
    if (isset($_SESSION['token'])) {
        $header[] = "Authorization: Bearer " . $_SESSION['token'];
    }
    if (isset($_COOKIE['language'])) {
        $language = $_COOKIE['language'];
    } elseif (isset($_SESSION['language'])) {
        $language = $_SESSION['language'];
    } else {
        $language = DEFAULT_LANGUAGE;
    }
    $header[] = "Accept-Language: " . $language;
    $header[] = "V-HTTP-CLIENT-IP: " . getIp();
    // $header[] = "Accept-Language: " . isset($_SESSION['language']) ? $_SESSION['language'] : DEFAULT_LANGUAGE;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}




function GetInitHeaders()
{
    $headers = array();
    if (isset($_SESSION['token'])) {
        $headers['Authorization'] = "Bearer " . $_SESSION['token'];
    }
    $language = isset($_SESSION['language']) ? $_SESSION['language'] : DEFAULT_LANGUAGE;
    $headers['Accept-Language'] = $language;
    return $headers;
}

	
function getIp()
{
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        $ip = $_SERVER["HTTP_CF_CONNECTING_IP"];
    } elseif (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
        $ip = getenv('HTTP_CLIENT_IP');
    } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
        $ip = explode(",", getenv('HTTP_X_FORWARDED_FOR'))[0];
    } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
        $ip = getenv('REMOTE_ADDR');
    } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
        $ip = $_SERVER['REMOTE_ADDR'];
    } else {
        $ip = "unknown";
    }
    return $ip;
}

/*
function curlGet($url, $data = '')
{
    $post = array();
    $query = http_build_query($data);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, 0);
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
*/