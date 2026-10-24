<?php
require_once '_init.php';

$data = array();
$data['username'] = $_POST['username'];
$data['password'] = $_POST['password'];
$result = curlPost('api/login', $data);
if ($result['code'] == 1000) {
    $_SESSION['token'] = $result['data']['token'];
    $_SESSION['id'] = $result['data']['id'];
    $_SESSION['username'] = $result['data']['username'];
    $_SESSION['expire'] = date('U', strtotime('+1day'));
}
echo json_encode($result);
?>