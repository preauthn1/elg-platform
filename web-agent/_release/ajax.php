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
    	// 管理營運商要使用的驗證方式
    case 'validation_setting':
        echo validationSetting();
        break;
    	// 設定域名資料
    case 'save_domain':
        echo saveDomain();
        break;
    	// 儲存營運商
    case 'save_agent':
        echo saveAgent();
        break;
    	// 確認自已密碼
    case 'confirm_password':
        echo confirmPassword();
        break;
    	// 切換營運商狀態
    case 'agent_toggle_status':
        echo agentToggleStatus();
        break;
    	// 切換營運商前台註冊狀態
    case 'agent_toggle_register':
        echo agentToggleRegister();
        break;
    	// 切換會員狀態
    case 'member_toggle_status':
        echo memberToggleStatus();
        break;
    	// 切換會員認證狀態
    case 'member_toggle_validate':
        echo memberToggleValidate();
        break;
    	// 設定認證狀態
    case 'save_validate':
        echo saveValidate();
        break;
    	// 儲存營運商
    case 'save_member':
        echo saveMember();
        break;
    	// 設定要開啟的遊戲商
    case 'vendor_setting':
        echo vendorSetting();
        break;
    	// 儲存活動
    case 'save_event':
        echo saveEvent();
        break;
    	// 代理開洗分
    case 'agent_transaction':
        echo agentTransaction();
        break;
    	// 會員開洗分
    case 'member_transaction':
        echo memberTransaction();
        break;
    	// 取玩家遊戲商帳號
    case 'get_vendor_username':
        echo getVendorUsername();
        break;
    	// 轉點失敗補點
    case 'refund_transaction':
        echo refundTransaction();
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

// 管理營運商要使用的驗證方式
function validationSetting()
{
    $data = array();
    $data['type_id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/validation/setting', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 確認密碼
function confirmPassword()
{
    $data = array();
    $data['password'] = $_POST['original_password'];
    $result = curlPost('api/agent/confirmPassword', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存營運商
function saveAgent()
{
    $data = array();
    if ($_POST['parent'] != '') {
        $data['parent'] = $_POST['parent'];
    }
    $data['is_sub_account'] = $_POST['is_sub_account'];
    $data['nickname'] = $_POST['nickname'];
    $data['email'] = $_POST['email'];
    $data['mobile'] = $_POST['mobile'];
    $data['country_id'] = $_POST['country_id'];
    $data['city'] = $_POST['city'];
    $data['address'] = $_POST['address'];
    $data['percentage'] = $_POST['percentage'];
    $data['status'] = $_POST['status'];
    $data['mobile_validate'] = $_POST['mobile_validate'];
    $data['email_validate'] = $_POST['email_validate'];
    $data['frontend_register'] = $_POST['frontend_register'];
    $data['agent_mode'] = $_POST['agent_mode'];
    if ($_POST['password'] != '') {
        $data['password'] = $_POST['password'];
        $data['password_confirmation'] = $_POST['password_confirmation'];
    }
    if ($_POST['id'] == '') {
        $data['username'] = $_POST['username'];
        $result = curlPost('api/agent/add', $data);
        if (isset($result['data']) && $result['code'] == 1000) {
            $data['id'] = $result['data'];
        } else {
            $result = ['code' => 0,'message' => $result['data']];
        }
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/agent/edit', $data);
    }
    if (isset($data['id'])) {
        // 設定權限
        $permission = array();
        $permission['agent_id'] = $data['id'];
        $menus_array = array();
        if (is_array($_POST['agent_menus'])) {
            foreach ($_POST['agent_menus'] as $row) {
                $menus_array[$row] = '';
            }
        }
        $permission['menus'] = $menus_array;
        $result = curlPost('api/agentPermission/setting', $permission);
    }

    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 設定域名資料
function saveDomain()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['title'] = $_POST['title'];
    $data['logo_pc'] = $_POST['logo_pc'];
    $data['logo_mobile'] = $_POST['logo_mobile'];
    $data['icon'] = $_POST['logo_icon'];
    $data['template_id'] = $_POST['template_id'];
    $result = curlPost('api/agentDomain/setting', $data);
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

// 切換營運商前台註冊狀態
function agentToggleRegister()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['frontend_register'] = $_POST['status'];
    $result = curlPost('api/agent/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換會員狀態
function memberToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/member/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換會員認證狀態
function memberToggleValidate()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['is_validate'] = $_POST['status'];
    $result = curlPost('api/member/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 設定認證狀態
function saveValidate()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $data['remark'] = $_POST['remark'];
    $result = curlPost('api/memberValidation/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存會員
function saveMember()
{
    $data = array();
    $data['first_name'] = $_POST['first_name'];
    $data['last_name'] = $_POST['last_name'];
    $data['nickname'] = $_POST['nickname'];
    $data['email'] = $_POST['email'];
    if ($_POST['mobile'] != '') {
        $data['mobile'] = $_POST['country_code'] . $_POST['mobile'];
    }
    $data['country_id'] = $_POST['country_id'];
    $data['city'] = $_POST['city'];
    $data['address'] = $_POST['address'];
    $data['status'] = $_POST['status'];
    if ($_POST['password'] != '') {
        $data['password'] = $_POST['password'];
        $data['password_confirmation'] = $_POST['password_confirmation'];
    }
    if ($_POST['id'] == '') {
        $data['username'] = $_POST['username'];
        $data['parent'] = $_POST['parent'];
        $result = curlPost('api/member/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/member/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 設定要開啟的遊戲商
function vendorSetting()
{
    $data = array();
    $data['agent_id'] = $_POST['agent_id'];
    $data['vendor_id'] = $_POST['id'];
    if (isset($_POST['status'])) {
        $data['status'] = $_POST['status'];
    }
    if (isset($_POST['backend_show'])) {
        $data['backend_show'] = $_POST['backend_show'];
    }
    $result = curlPost('api/agentVendor/setting', $data);
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
                'end_time' => substr($_POST['range'][$key], 19, 16) . ':59'
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
    $data['prize_expire_date'] = $_POST['prize_expire_date'] . ':59';
    $data['prize_draw_date'] = $_POST['prize_draw_date'] . ':59';

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

// 代理間開洗分
function agentTransaction()
{
    if (is_numeric($_POST['point']) &&  $_POST['point'] > 0) {
        $data = array();
        $data['agent_id'] = $_POST['id'];
        $data['type'] = $_POST['type'];
        $data['point'] = $_POST['point'];
        $result = curlPost('api/agentTransaction', $data);
        if ($result['code'] == 1000) {
            //如果成功就更新餘額
            $_SESSION['balance'] = $result['data']['from_balance'];
        }
    } else {
        $result = ['code' => -1, 'message' => '請輸入大於0的數字'];
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 會員開洗分
function memberTransaction()
{
    if (is_numeric($_POST['point']) &&  $_POST['point'] > 0) {
        $data = array();
        $data['member_id'] = $_POST['id'];
        $data['type'] = $_POST['type'];
        $data['point'] = $_POST['point'];
        $result = curlPost('api/memberTransaction', $data);
        if ($result['code'] == 1000) {
            //如果成功就更新餘額
            $_SESSION['balance'] = $result['data']['agent_balance'];
        }
    } else {
        $result = ['code' => -1, 'message' => '請輸入大於0的數字'];
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}
function getVendorUsername()
{
    $data = array();
    $data['member_id'] = $_POST['member_id'];
    $data['vendor_id'] = $_POST['vendor_id'];
    $result = curlPost('api/vendor/username', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function refundTransaction()
{
    $data = array();
    $data['transaction_id'] = $_POST['uid'];
    $result = curlPost('api/refundTransaction', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}