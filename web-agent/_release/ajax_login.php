<?php
require_once '_init.php';

$data = array();
$data['username'] = $_POST['username'];
$data['password'] = $_POST['password'];
$result = curlPost('api/login', $data);
if ($result['code'] == 1000) {
    $_SESSION['token'] = $result['data']['token'];
    $_SESSION['code'] = $result['data']['code'];
    $_SESSION['id'] = $result['data']['id'];
    $_SESSION['host_id'] = $result['data']['host_id'];
    $_SESSION['parent'] = $result['data']['parent'];
    $_SESSION['balance'] = $result['data']['balance'];
    $_SESSION['has_transaction'] = $result['data']['agent_backend_transaction'];
    $_SESSION['username'] = $result['data']['username'];
    $_SESSION['expire'] = date('U', strtotime('+1day'));
}
echo json_encode($result);