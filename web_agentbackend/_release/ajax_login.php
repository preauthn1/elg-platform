<?php
require_once '_init.php';

$data = array();
$data['action'] = $_POST['action'];
if ($_POST['action'] == 'auth') {
    $data['secret'] = $_POST['secret'];
    $data['username'] = $_SESSION['username'];
    $data['password'] = $_SESSION['password'];
} else {
    $data['username'] = $_POST['username'];
    $data['password'] = $_POST['password'];
}

$result = curlPost('api/login', $data);

if ($result['code'] == 1000) {
    //google 2fa
    if ($result['data']['action'] == 'auth') {
        $_SESSION['action'] = 'auth';
        $_SESSION['auth_2fa'] = false;
        $_SESSION['username'] = $_POST['username'];
        $_SESSION['password'] = $_POST['password'];
    } else {
        $_SESSION['action'] = 'index';
        $_SESSION['id'] = $result['data']['id'];
        $_SESSION['host_id'] = $result['data']['host_id'];
        $_SESSION['username'] = $result['data']['username'];
        $_SESSION['token'] = $result['data']['token'];
        $_SESSION['code'] = $result['data']['code'];
        $_SESSION['parent'] = $result['data']['parent'];
        $_SESSION['role_id'] = $result['data']['role_id'];
        $_SESSION['hierarchy'] = $result['data']['hierarchy'];
        $_SESSION['hierarchy_path'] = $result['data']['hierarchy_path'];
        $_SESSION['balance'] = $result['data']['balance'];
        $_SESSION['has_transaction'] = $result['data']['agent_backend_transaction'];
        $_SESSION['toggle_thousands'] = 0;
        $_SESSION['is_maintenance_whitelist'] = $result['data']['is_maintenance_whitelists'];
        $_SESSION['expire'] = date('U', strtotime('+1day'));
        $_SESSION['auth_2fa'] = true;
        unset($_SESSION['password']);
    }
}
echo json_encode($result);