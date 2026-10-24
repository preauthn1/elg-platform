<?php
require_once '_init.php';
switch ($_POST['action']) {
        // 登出
    case 'logout':
        echo logout();
        break;
        // 切換語言
    case 'language':
        echo language();
        break;
        // 儲存管理員
    case 'save_user':
        echo saveUser();
        break;
        // 切換管理員狀態
    case 'user_toggle_status':
        echo userToggleStatus();
        break;
        // 儲存營運商
    case 'save_agent':
        echo saveAgent();
        break;
        // 切換營運商狀態
    case 'agent_toggle_status':
        echo agentToggleStatus();
        break;
        // 設定營運商可用模版
    case 'agent_template_setting':
        echo agentTemplateSetting();
        break;
        // 儲存版位
    case 'save_advertisement_path':
        echo saveAdvertisementPath();
        break;
        // 儲存廣告
    case 'save_advertisement':
        echo saveAdvertisement();
        break;
        // 儲存國家
    case 'save_country':
        echo saveCountry();
        break;
        // 切換國家狀態
    case 'country_toggle_status':
        echo countryToggleStatus();
        break;
        // 儲存幣種
    case 'save_currency':
        echo saveCurrency();
        break;
        // 切換幣種狀態
    case 'currency_toggle_status':
        echo currencyToggleStatus();
        break;
        // 儲存遊戲類別
    case 'save_game_type':
        echo saveGameType();
        break;
        // 切換遊戲類別狀態
    case 'game_type_toggle_status':
        echo gameTypeToggleStatus();
        break;
        // 儲存模版
    case 'save_template':
        echo saveTemplate();
        break;
        // 切換域名狀態
    case 'domain_toggle_status':
        echo domainToggleStatus();
        break;
        // 新增域名
    case 'domain_add':
        echo domainAdd();
        break;
        // 刪除域名
    case 'domain_delete':
        echo domainDelete();
        break;
        // 切換遊戲商狀態
    case 'vendor_toggle_status':
        echo vendorToggleStatus();
        break;
        // 儲存遊戲商
    case 'save_vendor':
        echo saveVendor();
        break;
        // 切換遊戲狀態
    case 'game_toggle_status':
        echo gameToggleStatus();
        break;
        // 儲存遊戲
    case 'save_game':
        echo saveGame();
        break;
        // 儲存活動
    case 'save_event':
        echo saveEvent();
        break;
}

// 登出
function logout()
{
    session_destroy();
}

// 切換語言
function language()
{
    setcookie('language', $_POST['lang']);
    $_SESSION['language'] = $_POST['lang'];
    return $_SESSION['language'];
}

// 儲存管理員
function saveUser()
{
    $data = array();
    $data['email'] = $_POST['email'];
    $data['nickname'] = $_POST['nickname'];
    $data['mobile'] = $_POST['mobile'];
    $data['status'] = $_POST['status'];
    if ($_POST['password'] != '') {
        $data['password'] = $_POST['password'];
        $data['password_confirmation'] = $_POST['password_confirmation'];
    }
    if ($_POST['id'] == '') {
        $data['username'] = $_POST['username'];
        $result = curlPost('api/user/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/user/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換管理員狀態
function userToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/user/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存營運商
function saveAgent()
{
    $data = array();
    $data['nickname'] = $_POST['nickname'];
    $data['email'] = $_POST['email'];
    $data['mobile'] = $_POST['mobile'];
    $data['country_id'] = $_POST['country_id'];
    $data['city'] = $_POST['city'];
    $data['address'] = $_POST['address'];
    $data['percentage'] = $_POST['percentage'];
    $data['status'] = $_POST['status'];
    $data['has_pc'] = $_POST['has_pc'];
    $data['has_mobile'] = $_POST['has_mobile'];
    $data['has_chat'] = $_POST['has_chat'];
    $data['has_online_payment'] = $_POST['has_online_payment'];
    $data['has_app'] = $_POST['has_app'];
    $data['transaction_all'] = $_POST['transaction_all'];
    $data['agent_backend_transaction'] = $_POST['agent_backend_transaction'];
    $data['frontend_register'] = $_POST['frontend_register'];
    if ($_POST['password'] != '') {
        $data['password'] = $_POST['password'];
        $data['password_confirmation'] = $_POST['password_confirmation'];
    }
    if ($_POST['id'] == '') {
        $data['username'] = $_POST['username'];
        $result = curlPost('api/agent/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/agent/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換營運商狀態
function agentToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/agent/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 設定營運商可用模版
function agentTemplateSetting()
{
    $data = array();
    $data['agent_id'] = $_POST['id'];
    $data['template_id'] = $_POST['template_id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/agentTemplate/setting', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存廣告版位
function saveAdvertisementPath()
{
    $data = array();
    $data['name'] = $_POST['name'];
    $data['column'] = $_POST['column'];
    $data['width'] = $_POST['width'];
    $data['height'] = $_POST['height'];
    $data['status'] = $_POST['status'];
    if ($_POST['id'] == '') {
        $result = curlPost('api/advertisementPath/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/advertisementPath/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存廣告
function saveAdvertisement()
{
    $data = array();
    $data['language_id'] = $_POST['language'];
    $data['path_id'] = $_POST['path_id'];
    if (isset($_POST['range'])) {
        $data['start_time'] = substr($_POST['range'], 0, 10) . ' 00:00:00';
        $data['end_time'] = substr($_POST['range'], -10) . ' 23:59:59';
    }
    if (isset($_POST['country'])) {
        $data['country_id'] = str_replace(':"on"', '', json_encode($_POST['country']));
    } else {
        $data['country_id'] = '';
    }
    if (isset($_POST['agent'])) {
        $data['agent_id'] = str_replace(':"on"', '', json_encode($_POST['agent']));
    } else {
        $data['agent_id'] = '';
    }
    if ($_POST['type'] == 1) {
        $data['game_type_id'] = $_POST['game_type_id'];
        $data['game_id'] = 0;
        $data['vendor_id'] = 0;
    } else if ($_POST['type'] == 2) {
        $data['game_id'] = $_POST['game_id'];
        $data['game_type_id'] = 0;
        $data['vendor_id'] = 0;
    } else if ($_POST['type'] == 3) {
        $data['game_id'] = 0;
        $data['game_type_id'] = 0;
        $data['vendor_id'] = $_POST['vendor_id'];
    }
    if ($_POST['img_image'] != '') {
        $data['image'] = $_POST['img_image'];
    }
    if ($_POST['img_image_pc'] != '') {
        $data['image_pc'] = $_POST['img_image_pc'];
    }
    if ($_POST['id'] == '') {
        $result = curlPost('api/advertisement/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/advertisement/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存國家
function saveCountry()
{
    $data = array();
    $data['code'] = $_POST['code'];
    $data['name'] = $_POST['name'];
    $data['country_code'] = $_POST['country_code'];
    $data['phone_rule'] = $_POST['phone_rule'];
    $data['status'] = isset($_POST['status']) ? 1 : 0;
    $names = array();
    foreach ($_POST['names'] as $key => $value) {
        if ($value != '') {
            $names[] = array(
                'language_id' => $key,
                'name' => $value
            );
        }
    }
    if (count($names) > 0) {
        $data['names'] = $names;
    }
    if ($_POST['id'] == '') {
        $result = curlPost('api/country/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/country/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換國家狀態
function countryToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/country/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存幣種
function saveCurrency()
{
    $data = array();
    $data['code'] = $_POST['code'];
    $data['name'] = $_POST['name'];
    $data['rate'] = $_POST['rate'];
    $data['status'] = isset($_POST['status']) ? 1 : 0;
    $names = array();
    foreach ($_POST['names'] as $key => $value) {
        if ($value != '') {
            $names[] = array(
                'language_id' => $key,
                'name' => $value
            );
        }
    }
    if (count($names) > 0) {
        $data['names'] = $names;
    }
    if ($_POST['id'] == '') {
        $result = curlPost('api/currency/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/currency/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換幣種狀態
function currencyToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/currency/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存遊戲類別
function saveGameType()
{
    $data = array();
    $data['parent'] = $_POST['parent'];
    $data['name'] = $_POST['name'];
    $data['status'] = isset($_POST['status']) ? 1 : 0;
    $names = array();
    foreach ($_POST['names'] as $key => $value) {
        if ($value != '') {
            $names[] = array(
                'language_id' => $key,
                'name' => $value
            );
        }
    }
    if (count($names) > 0) {
        $data['names'] = $names;
    }
    if ($_POST['id'] == '') {
        $result = curlPost('api/gameType/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/gameType/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換遊戲類別狀態
function gameTypeToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/gameType/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存模版
function saveTemplate()
{
    $data = array();
    $data['name'] = $_POST['name'];
    $data['thumbnail'] = $_POST['image'];
    $data['status'] = isset($_POST['status']) ? 1 : 0;
    if ($_POST['id'] == '') {
        $result = curlPost('api/template/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/template/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換域名狀態
function domainToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/agentDomain/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 新增域名
function domainAdd()
{
    $data = array();
    $data['agent_id'] = $_POST['agent_id'];
    $data['domain'] = $_POST['domain'];
    $data['status'] = isset($_POST['status']) ? 1 : 0;
    $result = curlPost('api/agentDomain/add', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 刪除域名
function domainDelete()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $result = curlPost('api/agentDomain/delete', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換遊戲商狀態
function vendorToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/vendor/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存遊戲商
function saveVendor()
{
    $data = array();
    $data['name'] = $_POST['name'];
    $data['rate'] = $_POST['rate'];
    $data['percentage'] = $_POST['percentage'];
    $data['sort'] = $_POST['sort'];
    if ($_POST['image'] != '') {
        $data['image'] = $_POST['image'];
    }
    if ($_POST['app_icon'] != '') {
        $data['app_icon'] = $_POST['app_icon'];
    }
    $data['status'] = $_POST['status'];
    if ($_POST['id'] == '') {
        //遊戲商不可由後台新增
        $result = ['message' => '請選擇遊戲商'];
        // $result = curlPost('api/vendor/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/vendor/edit', $data);
    }
    //設定第一層代理是否顯示遊戲商
    $data = array();
    $data['vendor_id'] = $_POST['id'];
    foreach ($_POST as $key => $value) {
        if (strrpos($key, "agent") !== false) {
            $data['agent_id'] = str_replace('agent', '', $key);
            $data['status'] = $value;
            curlPost('api/agentVendor/setting', $data);
        }
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換遊戲狀態
function gameToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/game/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存遊戲
function saveGame()
{
    $data = array();
    $data['vendor_id'] = $_POST['vendor_id'];
    $data['type_id'] = $_POST['type_id'];
    $data['join_bet_event'] = $_POST['join_bet_event'];
    $data['join_win_event'] = $_POST['join_win_event'];
    $data['game_type'] = $_POST['game_type'];
    $data['name'] = $_POST['name'];
    $names = array();
    foreach ($_POST['names'] as $key => $value) {
        if ($value != '') {
            $names[] = array(
                'language_id' => $key,
                'name' => $value
            );
        }
    }
    if (count($names) > 0) {
        $data['names'] = $names;
    }
    if ($_POST['image'] != '') {
        $data['image'] = $_POST['image'];
    }
    $data['sort'] = $_POST['sort'];
    $data['is_new'] = $_POST['is_new'];
    $data['is_hot'] = $_POST['is_hot'];
    $data['status'] = $_POST['status'];
    if ($_POST['id'] == '') {
        $result = curlPost('api/game/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/game/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存活動
function saveEvent()
{
    $data = array();
    // step 1
    $data['type_id'] = $_POST['type_id'];
    $data['title'] = $_POST['title'];
    $data['content'] = $_POST['content'];
    $titles = array();
    foreach ($_POST['titles'] as $key => $value) {
        if ($value != '') {
            $titles[] = array(
                'language_id' => $key,
                'title' => $value,
                'content' => $_POST['contents'][$key]
            );
        }
    }
    if (count($titles) > 0) {
        $data['languages'] = $titles;
    }

    // step 2
    $details = array();
    foreach ($_POST['action_id'] as $key => $value) {
        if ($value != '') {
            $details[] = array(
                'action_id' => $_POST['action_id'][$key],
                'order_type_id' => $_POST['order_type_id'][$key],
                'vendor_id' => $_POST['vendor_id'][$key],
                'point' => $_POST['point'][$key],
                'start_time' => substr($_POST['range'][$key], 0, 16) . ':00',
                'end_time' => substr($_POST['range'][$key], -16) . ':59'
            );
        }
    }
    if (count($details) > 0) {
        $data['details'] = $details;
    }

    // step 3
    $data['prize_type_id'] = $_POST['prize_type_id'];
    $data['prize_name'] = $_POST['prize_name'];
    $data['prize_background'] = $_POST['background'];
    // $data['prize_number'] = $_POST['prize_number'];
    $data['prize_number'] = 1;
    $data['prize_is_repeatedly'] = $_POST['prize_is_repeatedly'];
    $data['prize_expire_date'] = $_POST['prize_expire_date'] . ' 23:59:59';

    // step 4
    $prizes = array();
    foreach ($_POST['prize_content_name'] as $key => $value) {
        if ($value != '') {
            $prizes[] = array(
                'name' => $_POST['prize_content_name'][$key],
                'point' => $_POST['prize_content_point'][$key],
                'percentage' => $_POST['prize_content_percentage'][$key],
                'number' => $_POST['prize_content_number'][$key]
            );
        }
    }
    if (count($prizes) > 0) {
        $data['prizes'] = $prizes;
    }
    // step 5
    $assign = array();
    foreach ($_POST['assign_agent_id'] as $key => $value) {
        if ($value != '') {
            $assign[] = array(
                'agent_id' => $_POST['assign_agent_id'][$key],
                'percentage' => $_POST['assign_percentage'][$key]
            );
        }
    }
    if (count($assign) > 0) {
        $data['assign'] = $assign;
    }
    if ($_POST['id'] == '') {
        $result = curlPost('api/event/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/event/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}