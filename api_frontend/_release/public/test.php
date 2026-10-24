<?php
if (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
    $userip = getenv('HTTP_CLIENT_IP');
} elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
    $userip = getenv('HTTP_X_FORWARDED_FOR');
} elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
    $userip = getenv('REMOTE_ADDR');
} elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
    $userip = $_SERVER['REMOTE_ADDR'];
}
list ($userip) = explode(',', $userip);
if ($userip != '::1' && $userip != '0.0.0.0') {
    echo 'bye!!';
    exit();
}
// TEST AG
$opts = array(
    'http' => array(
        'method' => "GET",
        'timeout' => 60
    )
);

$context = stream_context_create($opts);
echo file_get_contents("https://example.invalid/", false, $context);
echo '</br>';
$file = 'https://example.invalid/doBusiness.do';
$file = 'https://example.invalid/';
$file_headers = get_url_headers($file);
// if (! $file_headers || $file_headers[0] == 'HTTP/1.1 404 Not Found') {
if (! $file_headers) {
    $exists = false;
} else {
    $exists = true;
}
echo 'AG:';
var_dump($exists);
echo '</br>';
echo '</br>';

// TEST 918
$file = 'http://prod2.api2u.mobi/scr2api/player';
$file_headers = get_url_headers($file);
if (! $file_headers) {
    $exists = false;
} else {
    $exists = true;
}
echo '</br>918:';
var_dump($exists);
echo '</br>';
echo '</br>';

// TEST Joker
$file = 'http://example.invalid:81';
$file_headers = get_url_headers($file);
if (! $file_headers) {
    $exists = false;
} else {
    $exists = true;
}
echo '</br>Joker:';
var_dump($exists);
echo '</br>';
echo '</br>';

// TEST 豪龍
$file = 'http://prod2.api2u.mobi/scr2api/createPlayer.aspx';
$file_headers = get_url_headers($file);
if (! $file_headers) {
    $exists = false;
} else {
    $exists = true;
}
echo '</br>豪龍：:';
var_dump($exists);
echo '</br>';
echo '</br>';

function get_url_headers($url, $timeout = 10)
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $data = curl_exec($ch);
    var_dump($data);
    echo '</br>';
    echo '</br>';
    $data = preg_split('/\n/', $data);
    $data = array_filter(array_map(function ($data) {
        $data = trim($data);
        if ($data) {
            $data = preg_split('/:\s/', trim($data), 2);
            $length = count($data);
            switch ($length) {
                case 2:
                    return array(
                        $data[0] => $data[1]
                    );
                    break;
                case 1:
                    return $data;
                    break;
                default:
                    break;
            }
        }
    }, $data));

    sort($data);

    foreach ($data as $key => $value) {
        $itemKey = array_keys($value)[0];
        if (is_int($itemKey)) {
            $data[$key] = $value[$itemKey];
        } elseif (is_string($itemKey)) {
            $data[$itemKey] = $value[$itemKey];
            unset($data[$key]);
        }
    }

    return $data;
}