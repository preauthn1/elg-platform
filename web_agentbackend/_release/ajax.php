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
    // 切換千位數
    case 'toggle_thousands':
        echo toggle_thousands();
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
    // 切換營運商2fa狀態
    case 'agent_toggle_google2fa':
        echo agentToggleGoogle2fa();
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
    //遊戲商維護
    case "save_vendor_maintenances":
        echo save_vendor_maintenances();
        break;
    // 設定要開啟的遊戲商(強制設定)
    case 'vendor_setting_force_create':
        echo vendor_setting_force_create();
        break;
    // 儲存活動
    case 'save_event':
        echo saveEvent();
        break;
    case 'save_new_event':
        echo saveNewEvent();
        break;
    // 切換活動狀態
    case 'event_toggle_status':
        echo eventToggleStatus();
        break;
    // 儲存登入活動
    case 'save_login_event':
        echo saveLoginEvent();
        break;
    // 切換登入活動狀態
    case 'login_event_toggle_status':
        echo loginEventToggleStatus();
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
    case 'save_bank':
        echo saveBank();
        break;
    case 'save_bank_account':
        echo saveBankAccount();
        break;
    case 'account_toggle_status':
        echo accountToggleStatus();
        break;
    case 'bank_toggle_status':
        echo bankToggleStatus();
        break;
    case 'review_order':
        echo reviewOrder();
        break;
    case 'save_announcement_messages':
        echo saveAnnouncementMessages();
        break;
    case 'delete_announcement_messages':
        echo deleteAnnouncementMessages();
        break;
    case 'migrate_agent':
        echo migrate_agent();
        break;
    case 'get_agent_detail':
        echo get_agent_detail();
        break;
    case 'check_hierarchy':
        echo check_hierarchy();
        break;
    case 'update_advance_setting':
        echo updateAgentAdvanceSetting();
        break;
    case 'create_agent_group':
        echo createAgentGroup();
        break;
    case 'edit_agent_group':
        echo edit_agent_group();
        break;
    case 'update_agent_customized_setting':
        echo update_agent_customized_setting();
        break;
    case 'update_all_language_text':
        echo update_all_language_text();
        break;
    case 'create_agent_setting_define':
        echo create_agent_setting_define();
        break;
    case 'copy_create_new_group':
        echo copy_create_new_group();
        break;
    case 'copy_setting_insert_sql':
        echo copy_setting_insert_sql();
        break;
    case 'auto_assign_customized_default_value':
        echo auto_assign_customized_default_value();
        break;
    case 'sync_child_permission':
        echo sync_child_permission();
        break;
    case 'review_withdrawal_order':
        echo reviewWithdrawalOrder();
        break;
    case 'save_withdrawal_bank':
        echo saveWithDrawalBank();
        break;
    case 'save_withdrawal_bank_name':
        echo saveWithDrawalBankName();
        break;
    case 'withdrawl_bank_toggle_tatus':
    case 'withdrawal_bank_toggle_status':
        echo WithdrawlBankToggleStatus();
        break;
    case 'lucky_ticket_manual_draw':
        echo LuckyTicketManualDraw();
        break;
    case 'w_jackpot_ticket_manual_draw':
        echo WJackpotTicketManualDraw();
        break;
    case 'get_vendor_balance':
        echo get_vendor_balance();
        break;
    case 'get_vendor_transfer_in':
        echo get_vendor_transfer_in();
        break;
    case 'save_sms_gateway':
        echo saveSmsGateway();
        break;
    case 'save_sms_gateway_info':
        echo saveSmsGatewayInfo();
        break;
    // 切換簡訊商狀態
    case 'sms_gateway_toggle_status':
        echo smsGatewayToggleStatus();
        break;
    // 切換簡訊商戶資訊狀態
    case 'sms_info_toggle_status':
        echo smsInfoToggleStatus();
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
    // 儲存版位
    case 'save_advertisement_path':
        echo saveAdvertisementPath();
        break;
    // 選擇已儲存廣告圖片
    case 'select_advertise_image':
        echo selectAdvertiseImage();
        break;
    // 儲存廣告
    case 'save_advertise':
        echo saveAdvertise();
        break;
    // 儲存廣告
    case 'save_advertisement':
        echo saveAdvertisement();
        break;
    case 'update_agent_sms_setting':
        echo updateAgentSmsSetting();
        break;
    case 'agentCashFlowToggleStatus':
        echo agentCashFlowToggleStatus();
        break;
    case 'cashFlowToggleStatus':
        echo cashFlowToggleStatus();
        break;
    case 'updateAgentCashFlow':
        echo updateAgentCashFlow();
        break;
    case 'updateCashFlow':
        echo updateCashFlow();
        break;
    case 'updateCashFlowOrderType':
        echo updateCashFlowOrderType();
        break;
    case 'acfOrderTypeList':
        echo acfOrderTypeList();
        break;
    case 'getOrderTypeList':
        echo getOrderTypeList();
        break;
    case 'getCashFlowOrderTypeName':
        echo getCashFlowOrderTypeName();
        break;
    case 'agentCashFlowOrderTypeToggleStatus':
        echo agentCashFlowOrderTypeToggleStatus();
        break;
    case 'updateAgentCashFlowOrderType':
        echo updateAgentCashFlowOrderType();
        break;
    case 'agentCashFlowOrderTypeDelete':
        echo agentCashFlowOrderTypeDelete();
        break;
    case 'cashFlowOrderTypeToggleStatus':
        echo cashFlowOrderTypeToggleStatus();
        break;
    case 'updateOrderTypeName':
        echo updateOrderTypeName();
        break;
    // 切換遊戲商狀態
    case 'vendor_management_toggle_status':
        echo vendorManagementToggleStatus();
        break;
    // 儲存遊戲商
    case 'save_vendor_management':
        echo saveVendorManagement();
        break;
    // 切換遊戲狀態
    case 'game_toggle_status':
        echo gameToggleStatus();
        break;
    // 儲存遊戲
    case 'save_game':
        echo saveGame();
        break;
    // 儲存遊戲類別
    case 'save_game_type':
        echo saveGameType();
        break;
    // 切換遊戲類別狀態
    case 'game_type_toggle_status':
        echo gameTypeToggleStatus();
        break;
    case 'save_vendor_info':
        echo saveVendorInfo();
        break;
    case 'pb_toggle_share':
        echo pbToggleShare();
        break;
    case 'point_barrel_set_cost':
        echo pointBarrelSetCost();
        break;
    case 'point_barrel_settle':
        echo pointBarrelSettle();
        break;
    case 'doPayoutProcess':
        echo doPayoutProcess();
        break;
    case 'cancel_flow_limit':
        echo cancel_flow_limit();
        break;
    case 'check_2fa_secret':
        echo check_2fa_secret();
        break;
    case 'update_transfer_limit':
        echo update_transfer_limit();
        break;
    case 'create_transfer_limit':
        echo create_transfer_limit();
        break;
    case 'upload_image':
        echo upload_image();
        break;
    case 'insert_unassign_vendorInfo':
        echo insert_unassign_vendorInfo();
        break;
    case 'edit_order_types':
        echo edit_order_types();
        break;
    case 'save_manual_deposit':
        echo save_manual_deposit();
        break;
    case 'reset_order_status':
        echo reset_order_status();
        break;
    case 'redis_flushDB':
        echo redis_flushDB();
        break;
    case 'helper_convert_excel_to_langFileFormat':
        echo helper_convert_excel_to_langFileFormat($lang);
        break;
    case 'cost_setting':
        echo cost_setting();
        break;
    case 'check_autoDeposit_and_autoWithdraw_amount_notice':
        echo check_autoDeposit_and_autoWithdraw_amount_notice();
        break;
    case 'get_autoDeposit_and_autoWithdraw_amount':
        echo get_autoDeposit_and_autoWithdraw_amount();
        break;
    case 'update_vendor_image':
        echo update_vendor_image();
        break;
    case 'toggle_backend_maintenance':
        echo toggle_backend_maintenance();
        break;
    case 'create_maintenance_whitelist':
        echo create_maintenance_whitelist();
        break;
    case 'delete_maintenance_whitelist':
        echo delete_maintenance_whitelist();
        break;
    case 'get_drop_down_list':
        echo get_drop_down_list();
        break;
    case 'update_agent_group_permission':
        echo update_agent_group_permission();
        break;
    case 'save_backend_announcement':
        echo save_backend_announcement();
        break;
    case 'delete_announcement':
        echo delete_announcement();
        break;
    case 'update_agent_announcement_status':
        echo update_agent_announcement_status();
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

// 切換千位數
function toggle_thousands()
{
    $status = $_POST['toggle_thousands'] == 0 ? 1 : 0;
    setcookie('toggle_thousands', $status);
    $_SESSION['toggle_thousands'] = $status;
    return $_SESSION['toggle_thousands'];
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
    $data['customized_setting_group_id'] = $_POST['customized_setting_group_id'];
    $data['cash_flow_group_id'] = $_POST['cash_flow_group_id'];
    $data['sms_gateway_group_id'] = $_POST['sms_gateway_group_id'];
    $data['chat_group_id'] = $_POST['chat_group_id'];
    $data['vendor_customize_group_id'] = $_POST['vendor_customize_group_id'];

    //2021/3/16 新增
    $data['has_pc'] = $_POST['has_pc'];
    $data['has_mobile'] = $_POST['has_mobile'];
    $data['has_apk'] = $_POST['has_apk'];
    $data['has_ipa'] = $_POST['has_ipa'];
    $data['has_chat'] = $_POST['has_chat'];
    $data['agent_backend_transaction'] = $_POST['agent_backend_transaction'];

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
            $result = ['code' => 0, 'message' => $result['data']];
        }
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/agent/edit', $data);
    }

    if ($result["code"] == 1000 && isset($data['id'])) {
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
    $data['domain'] = $_POST['domain'];
    $data['title'] = $_POST['title'];
    $data['logo_pc'] = $_POST['logo_pc'];
    $data['logo_mobile'] = $_POST['logo_mobile'];
    $data['icon'] = $_POST['logo_icon'];
    $data['add_home_image'] = $_POST['logo_add_home_image'];
    $data['template_id'] = $_POST['template_id'];
    $data['status'] = $_POST['status'];
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

// 切換營運商2fa狀態
function agentToggleGoogle2fa()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['has_2fa'] = $_POST['status'];
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
    $data['mobile_validate'] = $_POST['mobile_validate'];
    if ($_POST['password'] != '') {
        $data['password'] = $_POST['password'];
        $data['password_confirmation'] = $_POST['password_confirmation'];
    }
    if ($_POST['payment_password'] != '') {
        $data['payment_password'] = $_POST['payment_password'];
        $data['payment_password_confirmation'] = $_POST['payment_password_confirmation'];
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

// 設置遊戲商維護
function save_vendor_maintenances()
{
    $data = array();
    $data['type'] = $_POST['type'];
    $data['status'] = $_POST['status'];
    $data['vendor_id'] = $_POST['vendor_id'];
    $data['start_time'] = $_POST['start_time'];
    $data['end_time'] = $_POST['end_time'];
    $data['update_id'] = $_POST['update_id'];
    $data['agent_id'] = $_POST['agent_id'];
    $data['exclude_agent_id'] = $_POST['exclude_agent_id'];
    $result = curlPost('api/vendor_maintenances/update', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 設定要開啟的遊戲商 (強制設定、無視上級代理)
function vendor_setting_force_create()
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
    $result = curlPost('api/agentVendor/force_create', $data);
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
                'id' => $_POST['detail_id'][$key],
                'action_id' => $_POST['action_id'][$key],
                'order_type_id' => $_POST['order_type_id'][$key],
                'vendor_id' => $_POST['vendor_id'][$key],
                'game_type_id' => $_POST['game_type_id'][$key],
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
    $data['prize_withdrawal_cancel_reward'] = $_POST['prize_withdrawal_cancel_reward'];
    $data['prize_expire_date'] = $_POST['prize_expire_date'] . ':59';
    $data['prize_draw_date'] = $_POST['prize_draw_date'] . ':59';

    // step 4
    $prizes = array();
    foreach ($_POST['prize_content_name'] as $key => $value) {
        if ($value != '') {
            $prizes[] = array(
                'id' => $_POST['prize_content_id'][$key],
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
                'id' => $_POST['assign_id'][$key],
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

// 儲存活動
function saveNewEvent()
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
    $details['id'] = isset($_POST['detail_id']) ? $_POST['detail_id'] : 0;
    $details['action_id'] = $_POST['action_id'];
    $details['order_type_id'] = $_POST['order_type_id'];
    $details['vendor_id'] = $_POST['vendor_id'];
    $details['game_type_id'] = $_POST['game_type_id'];
    $details['point'] = ($_POST['action_id'] == 31) ? 1 : $_POST['point'];
    $details['bind_unlimited'] = $_POST['bind_unlimited'];
    $details['start_time'] = $_POST['start_time'] . ':00';
    $details['end_time'] = $_POST['end_time'] . ':59';
    $data['details'] = $details;

    // step 3
    $data['prize_type_id'] = $_POST['prize_type_id'];
    $data['prize_name'] = $_POST['prize_name'];
    //$data['prize_background'] = '';
    $data['prize_number'] = 1;
    $data['prize_is_repeatedly'] = ($_POST['action_id'] == 55) ? 2 : $_POST['prize_is_repeatedly'];
    $data['prize_withdrawal_cancel_reward'] = isset($_POST['prize_withdrawal_cancel_reward'])
        ? $_POST['prize_withdrawal_cancel_reward']
        : 0;
    $data['prize_flow_limit_multiple'] = $_POST['prize_flow_limit_multiple'];
    $data['prize_flow_limit_include_principal'] = isset($_POST['prize_flow_limit_include_principal'])
        ? $_POST['prize_flow_limit_include_principal']
        : 0;
    $data['prize_expire_date'] = $_POST['prize_expire_date'] . ':59';
    $data['prize_draw_date'] = $_POST['prize_draw_date'] . ':59';

    // step 4
    $prizes = array();
    foreach ($_POST['prize_content_name'] as $key => $value) {
        if ($value != '') {
            $prizes[] = array(
                'id' => $_POST['prize_content_id'][$key],
                'type' => $_POST['prize_content_type'][$key],
                'name' => $_POST['prize_content_name'][$key],
                'point' => (($_POST['prize_content_type'][$key] == 0) ? $_POST['prize_content_point'][$key] : 0),
                'point_percentage' => (($_POST['prize_content_type'][$key] == 1) ? $_POST['prize_content_point'][$key] : 0),
                'percentage' => $_POST['prize_content_percentage'][$key],
                'number' => $_POST['prize_content_number'][$key]
            );
        }
    }
    if (count($prizes) > 0) {
        $data['prizes'] = $prizes;
    }
    if ($_POST['id'] == '') {
        $result = curlPost('api/event/new_add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/event/new_edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換活動狀態
function eventToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/event/status', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存活動
function saveLoginEvent()
{
    $data = array();
    // step 1
    $data['type'] = $_POST['type'];
    $data['title'] = $_POST['title'];
    $data['content'] = $_POST['content'];
    $data['how_days'] = $_POST['how_days'];
    $data['range'] = $_POST['range'];
    $data['point'] = $_POST['point'];
    $data['times'] = $_POST['times'];
    $data['prize_is_repeatedly'] = $_POST['prize_is_repeatedly'];
    $data['repeat_hour'] = $_POST['repeat_hour'];
    $data['number_of_people'] = $_POST['number_of_people'];
    $data['start_time'] = $_POST['start_time'] . ':00';
    $data['end_time'] = $_POST['end_time'] . ':59';
    $data['vendor_id'] = $_POST['vendor_id'];

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

    if ($_POST['id'] == '') {
        $result = curlPost('api/loginEvent/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/loginEvent/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換登入活動狀態
function loginEventToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/loginEvent/status', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 代理間開洗分
function agentTransaction()
{
    if (is_numeric($_POST['point']) && $_POST['point'] > 0) {
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
    if (is_numeric($_POST['point']) && $_POST['point'] > 0) {
        $data = array();
        $data['member_id'] = $_POST['id'];
        $data['type'] = $_POST['type'];
        $data['point'] = $_POST['point'];
        $data['audit_amount'] = isset($_POST['audit_amount']) ? $_POST['audit_amount'] : 0;
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

function saveBank()
{
    $data = array();
    $data['bank_name'] = $_POST['bank_name'];
    $data['bank_code'] = $_POST['bank_code'];
    $data['status'] = $_POST['status'];
    if ($_POST['id'] == '') {
        $result = curlPost('api/bank/add', $data);
        if (isset($result['data']) && $result['code'] == 1000) {
            $data['id'] = $result['data'];
        } else {
            $result = ['code' => 0, 'message' => $result['message']];
        }
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/bank/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function saveBankAccount()
{
    $data = array();
    $data['account_name'] = $_POST['account_name'];
    $data['account_no'] = $_POST['account_no'];
    $data['min_amount'] = $_POST['min_amount'];
    $data['max_amount'] = $_POST['max_amount'];
    $data['status'] = $_POST['status'];
    if ($_POST['id'] == '') {
        $data['order_type_id'] = $_POST['bank'];
        $result = curlPost('api/bank/addAccount', $data);
        if ($result['code'] == 1000) {
            $result = ['code' => 1000, 'message' => $result['message']];
        } else {
            $result = ['code' => 0, 'message' => $result['data']];
        }
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/bank/editAccount', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function saveAnnouncementMessages()
{
    $data = array();
    $data['title'] = "";
    $data['message'] = $_POST['message'];
    $data['memo'] = $_POST['memo'];
    $data['type'] = $_POST['type'];
    $data['chat_group_id'] = $_POST['chat_group_id'];
    $data['hierarchy_agent_id'] = $_POST['hierarchy_agent_id'];
    $data['single_agent_id'] = $_POST['single_agent_id'];
    $data['member_id'] = $_POST['member_id'];
    $data['organization_id'] = $_POST['organization_id'];
    $data['start_time'] = $_POST['start_time'];
    $data['end_time'] = $_POST['end_time'];

    $result = curlPost('api/announcement-messages/create', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}


function doPayoutProcess()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/withdrawal/payout-process', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}


function cancel_flow_limit()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/member/cancel_flow_limit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function check_2fa_secret()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/2fa/auth', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function get_agent_detail()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/agent/view', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function check_hierarchy()
{
    $data = array();
    $data['test'] = 123;

    $result = curlPost('api/agent/hierarchy-check', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function migrate_agent()
{
    $data = array();
    $data['target_agent_id'] = $_POST['target_agent_id'];
    $data['new_parent_agent_id'] = $_POST['new_parent_agent_id'];

    $result = curlPost('api/agent/hierarchy-migrate', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function deleteAnnouncementMessages()
{
    $data = array();
    $data['announcement_message_id'] = $_POST['announcement_message_id'];

    $result = curlPost('api/announcement-messages/delete', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function updateAgentAdvanceSetting()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/agent/personal-settings/update', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function createAgentGroup()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/agent/group/create', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function edit_agent_group()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/agent/group/update', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function update_agent_customized_setting()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/agent/customized-settings/update', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function update_all_language_text()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/language-text/update', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function create_agent_setting_define()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/agent/setting-defines/create', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function copy_create_new_group()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/agent/group/create-by-copy', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function copy_setting_insert_sql()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/agent/insert-sql-generate', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function auto_assign_customized_default_value()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/agent/customized-settings/assign-default-value', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function sync_child_permission()
{
    $result = curlPost('api/agent/child/permission/sync', "");
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function accountToggleStatus()
{
    $data = array();
    $data['type'] = 2;
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/bank/status', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function bankToggleStatus()
{
    $data = array();
    $data['type'] = 1;
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/bank/status', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function reviewOrder()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $data['remark'] = $_POST['remark'];
    $result = curlPost('api/order/reviewOrder', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function reviewWithdrawalOrder()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['original_status'] = $_POST['original_status'];
    $data['status'] = $_POST['status'];
    $data['remark'] = $_POST['remark'];
    $result = curlPost('api/withdrawal/reviewOrder', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function saveWithDrawalBank()
{
    $data = array();
    $data['bank_name'] = $_POST['bank_name'];
    $data['bank_code'] = $_POST['bank_code'];
    $data['status'] = $_POST['status'];
    if ($_POST['id'] == '') {
        $result = curlPost('api/withdrawal/addBank', $data);
        if ($result['code'] == 1000) {
            $data['id'] = $result['data'];
        } else {
            $result = ['code' => 0, 'message' => $result['message']];
        }
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/withdrawal/editBank', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * @return false|string
 */
function saveWithDrawalBankName()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $name = array();
    $i = 1;
    while ($i <= $_POST['count']) {
        $name[$i] = $_POST[$i];
        $i++;
    }
    $data['bank'] = $name;
    if ($_POST['id'] !== '') {
        $result = curlPost('api/withdrawal/editBankName', $data);
        if ($result['code'] == 1000) {
            $data['id'] = $result['data'];
        } else {
            $result = ['code' => 0, 'message' => $result['data']];
        }
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function WithdrawlBankToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/withdrawal/status', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

//vjackpot抽獎券手動開獎
function LuckyTicketManualDraw()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/v_jackpot/manualDraw', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

//Wjackpot抽獎券手動開獎
function WJackpotTicketManualDraw()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/w_jackpot/manualDraw', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

//取得遊戲錢包餘額
function get_vendor_balance()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/member/vendor/balance', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

//遊戲錢包餘額轉回
function get_vendor_transfer_in()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/vendor/transferIn', $data, 60, 'internal');
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function saveSmsGateway()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/sms/gateway/save', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function saveSmsGatewayInfo()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/sms/info/save', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function smsGatewayToggleStatus()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/sms/gateway/status', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function smsInfoToggleStatus()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/sms/info/status', $data);
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

// 選擇已儲存廣告
function selectAdvertiseImage()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/advertise/image', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存廣告
function saveAdvertise()
{
    $data = array();
    $data['language_id'] = $_POST['language'];
    $data['path_id'] = $_POST['path_id'];
    $data['type'] = $_POST['type'];
    $data['status'] = $_POST['status'];
    $data['sort'] = $_POST['sort'];
    $data['start_time'] = $_POST['start_date'] . ':00';
    $data['end_time'] = $_POST['end_date'] . ':00';

    if (isset($_POST['parent'])) {
        $data['agent_id'] = $_POST['parent'];
    }

    $data['contents'] = '';
    if ($_POST['type'] == 1) {
        $data['contents'] = $_POST['game_type_id'];
    } elseif ($_POST['type'] == 2) {
        $data['contents'] = $_POST['game_id'];
    } elseif ($_POST['type'] == 3) {
        $data['contents'] = $_POST['vendor_id'];
    } elseif ($_POST['type'] == 4) {
        $data['contents'] = $_POST['external_url'];
        $data['open_window'] = isset($_POST['open_window']) ? $_POST['open_window'] : 0;
    } elseif ($_POST['type'] == 5) {
        $data['contents'] = $_POST['internal_type'];
    }

    foreach ($_POST['language'] as $row) {
        if ($_POST['img_image_' . $row] != '') {
            $data['image'][$row] = $_POST['img_image_' . $row];
        }
        $data['image_id'][$row] = $_POST['id_image_' . $row];
        if ($_POST['img_image_pc_' . $row] != '') {
            $data['image_pc'][$row] = $_POST['img_image_pc_' . $row];
        }
        $data['image_pc_id'][$row] = $_POST['id_image_pc_' . $row];
    }

    if ($_POST['id'] == '') {
        $result = curlPost('api/advertise/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/advertise/edit', $data);
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
        $data['agent_id'] = $_POST['agent'];
    }

    $data['game_id'] = 0;
    $data['game_type_id'] = 0;
    $data['vendor_id'] = 0;
    $data['external_url'] = '';
    if ($_POST['type'] == 1) {
        $data['game_type_id'] = $_POST['game_type_id'];
    } elseif ($_POST['type'] == 2) {
        $data['game_id'] = $_POST['game_id'];
    } elseif ($_POST['type'] == 3) {
        $data['vendor_id'] = $_POST['vendor_id'];
    } elseif ($_POST['type'] == 4) {
        $data['external_url'] = $_POST['external_url'];
    }

    foreach($_POST['language'] as $row) {
        if ($_POST['img_image_' . $row] != '') {
            $data['image'][$row] = $_POST['img_image_' . $row];
        }
        if ($_POST['img_image_pc_' . $row] != '') {
            $data['image_pc'][$row] = $_POST['img_image_pc_' . $row];
        }
    }

    if ($_POST['id'] == '') {
        $result = curlPost('api/advertisement/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/advertisement/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function updateAgentSmsSetting()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $url = ($_POST['type'] == 'detail')
        ? 'group_detail'
        : 'text';


    $result = curlPost('api/sms/' . $url . '/save', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function agentCashFlowToggleStatus()
{
    $data = array();
    $data['acf_id'] = $_POST['acf_id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/agent/onlineDeposit/agentCashFlowToggleStatus', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function updateAgentCashFlow()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['cash_flow_id'] = $_POST['cash_flow_id'];
    $data['rate'] = $_POST['rate'];
    $data['sorts'] = $_POST['sorts'];
    $data['info'] = $_POST['info'];
    $data['group_id'] = $_POST['group_id'];
    $result = curlPost('api/agent/onlineDeposit/updateAgentCashFlow', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function updateCashFlow()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['name'] = $_POST['name'];
    $data['type'] = $_POST['type'];
    $result = curlPost('api/agent/onlineDeposit/updateCashFlow', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function updateCashFlowOrderType()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['cash_flow_id'] = $_POST['cash_flow_id'];
    $data['name'] = $_POST['name'];
    $data['key'] = $_POST['key'];
    $data['sorts'] = $_POST['sorts'];
    $result = curlPost('api/agent/onlineDeposit/updateCashFlowOrderType', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function acfOrderTypeList()
{
    $data = array();
    $data['acf_id'] = $_POST['acf_id'];
    $result = curlPost('api/agent/onlineDeposit/acfOrderTypeList', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function agentCashFlowOrderTypeToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/agent/onlineDeposit/agentCashFlowOrderTypeToggleStatus', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function updateAgentCashFlowOrderType()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['agent_cash_flow_id'] = $_POST['agent_cash_flow_id'];
    $data['order_type_id'] = $_POST['order_type_id'];
    $data['min_amount'] = $_POST['min_amount'];
    $data['max_amount'] = $_POST['max_amount'];
    $data['sorts'] = $_POST['sorts'];
    $data['available_amount'] = $_POST['available_amount'];
    $result = curlPost('api/agent/onlineDeposit/updateAgentCashFlowOrderType', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function agentCashFlowOrderTypeDelete()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $result = curlPost('api/agent/onlineDeposit/agentCashFlowOrderTypeDelete', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 切換遊戲商狀態
function VendorManagementToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/vendor/edit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

// 儲存遊戲商
function saveVendorManagement()
{
    $data = array();
    $data['name'] = $_POST['name'];
    $data['rate'] = $_POST['rate'];
    $data['percentage'] = $_POST['percentage'];
    $data['sort'] = $_POST['sort'];
    $data['valid_bet_rate'] = $_POST['valid_bet_rate'];
    $data['remark'] = $_POST['remark'];
    if ($_POST['image'] != '') {
        $data['image'] = $_POST['image'];
    }
    if ($_POST['app_icon'] != '') {
        $data['app_icon'] = $_POST['app_icon'];
    }
    $data['status'] = $_POST['status'];
    $data['open_window'] = $_POST['open_window'];
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
            curlPost('api/agentVendor/settingBackend', $data);
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

    $data['memo'] = $_POST['memo'];
    $data['is_new'] = $_POST['is_new'];
    $data['is_hot'] = $_POST['is_hot'];
    $data['status'] = $_POST['status'];
    $data['sort'] = $_POST['sort'];
    $data['valid_bet_rate'] = $_POST['valid_bet_rate'];
    if ($_POST['id'] == '') {
        $result = curlPost('api/game/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/game/edit', $data);
    }
    $dataPost = array();
    foreach ($_POST as $key => $param) {
        if (strpos($key, "is_new") !== false) {
            $dataPost['data'][str_replace('is_new', '', $key)]['is_new'] = 1;
        }
        if (strpos($key, "vig_status") !== false) {
            $dataPost['data'][str_replace('vig_status', '', $key)]['status'] = $_POST[$key];
        }
        if (strpos($key, "is_hot") !== false) {
            $dataPost['data'][str_replace('is_hot', '', $key)]['is_hot'] = 1;
        }
        if (strpos($key, "join_bet_event") !== false) {
            $dataPost['data'][str_replace('join_bet_event', '', $key)]['join_bet_event'] = 1;
        }
        if (strpos($key, "join_win_event") !== false) {
            $dataPost['data'][str_replace('join_win_event', '', $key)]['join_win_event'] = 1;
        }
        if (strpos($key, "sort") !== false) {
            $dataPost['data'][str_replace('sort', '', $key)]['sort'] = $param;
        }
        if (strpos($key, "valid_bet_rate") !== false) {
            $dataPost['data'][str_replace('valid_bet_rate', '', $key)]['valid_bet_rate'] = $param;
        }
    }
    curlPost('api/game/editVendorInfoGame', $dataPost);
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

function saveVendorInfo()
{
    $data = array();
    $data['name'] = $_POST['name'];
    $data['vendor_id'] = $_POST['vendor_id'];
    $data['currency'] = $_POST['currency'];
    $data['info'] = $_POST['info'];
    $data['account_prefix'] = $_POST['account_prefix'];
    $data['prepared_account_number'] = $_POST['prepared_account_number'];
    $data['type'] = $_POST['type'];
    $data['created_at'] = $_POST['created_at'];
    $data['rate'] = $_POST['rate'];
    $data['status'] = $_POST['status'];
    $data['transfer_min_amount'] = $_POST['transfer_min_amount'];
    $games = $newgames = array();

    if ($_POST['id'] == '') {
        $result = curlPost('api/vendor_info/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        foreach ($_POST as $key => $value) {
            if (strpos($key, "games") !== false) {
                array_push($games, str_replace('games', '', $key));
            } elseif (strpos($key, "newgame") !== false) {
                array_push($newgames, str_replace('newgame', '', $key));
                array_push($games, str_replace('newgame', '', $key));
            }
        }
        $data['games'] = $games;
        $data['newgames'] = $newgames;
        $result = curlPost('api/vendor_info/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function cashFlowToggleStatus()
{
    $data = array();
    $data['cf_id'] = $_POST['cf_id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/agent/onlineDeposit/cashFlowToggleStatus', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function getOrderTypeList()
{
    $data = array();
    $data['cf_id'] = $_POST['cf_id'];
    $result = curlPost('api/agent/onlineDeposit/getOrderTypeList', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function cashFlowOrderTypeToggleStatus()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['status'] = $_POST['status'];
    $result = curlPost('api/agent/onlineDeposit/cashFlowOrderTypeToggleStatus', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function getCashFlowOrderTypeName()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $result = curlPost('api/agent/onlineDeposit/getCashFlowOrderTypeName', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function updateOrderTypeName()
{
    $data = array();
    $data['id'] = $_POST['order_type_id'];
    $name = array();
    foreach ($_POST as $key => $value) {
        if (strrpos($key, "order_type_name") !== false) {
            $name[str_replace('order_type_name', '', $key)] = $value;
        }
    }
    $data['name'] = $name;
    if ($_POST['id'] !== '') {
        $result = curlPost('api/agent/onlineDeposit/updateCashFlowOrderTypeName', $data);
        if ($result['code'] == 1000) {
            $data['id'] = $result['data'];
        } else {
            $result = ['code' => 0, 'message' => $result['data']];
        }
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function pbToggleShare()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['agent_id'] = $_POST['agent_id'];
    $data['share'] = $_POST['share'];
    $result = curlPost('api/point_barrel/point_share', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function pointBarrelSetCost()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/point_barrel/set_cost', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function pointBarrelSettle()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);
    $result = curlPost('api/point_barrel/settle', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function update_transfer_limit()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['type'] = $_POST['type'];
    $data['amount'] = $_POST['amount'];
    $data['memo'] = $_POST['memo'];

    $result = curlPost('api/transfer_limit/updateLimit', $data);
    if ($result['code'] !== 1000)
    {
        $result = ['code' => 0, 'message' => $result['message']];
    }

    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function create_transfer_limit()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['limit'] = $_POST['limit'];

    $result = curlPost('api/transfer_limit/createLimit', $data);
    if ($result['code'] !== 1000)
    {
        $result = ['code' => 0, 'message' => $result['data']];
    }

    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function upload_image()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/image/base64/upload', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function insert_unassign_vendorInfo()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/game/add/unassign-vendorInfo', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function edit_order_types()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/orderType/update', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}


function save_manual_deposit()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/order/manual-deposit', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function reset_order_status()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/order/reset-status', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function redis_flushDB()
{
    $json_data = json_encode($_POST);
    $data = json_decode($json_data);

    $result = curlPost('api/internal-helper/redis/flushDB', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}


function helper_convert_excel_to_langFileFormat($lang)
{
    $isCheck = $_POST["isCheck"];

    $applicationType = $_POST["applicationType"];

    $bodyJson = array();

    $bodyJson["applicationType"] = $applicationType;
    $bodyJson["excel_content"] = $_POST["excel_content"];

    //如果選擇　Web.AgentBack 要把多語系傳給API檢查
    if($applicationType == 51 || $applicationType == "51")
    {
        $existLangKeys = array_keys($lang);
        $bodyJson["compareTargetLangKeys"] = implode(',' , $existLangKeys);
    }

    $result = curlPostWithoutEncrypt('api/data-convert/excel/to/php-lang?isCheck='.$isCheck, $bodyJson);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function cost_setting()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $data['type'] = $_POST['type'];
    $data['cost'] = $_POST['cost'];
    $result = curlPost('api/cost/setting', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function check_autoDeposit_and_autoWithdraw_amount_notice()
{
    $data = array();
    $data['code'] = $_POST['code'];
    $result = curlPost('api/notice/checkNotice', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function get_autoDeposit_and_autoWithdraw_amount()
{
    $hasNewOrder = false;
    $response = curlPost('api/notice/autoDepositAndWithdraw');
    if($response['code'] == 1000){
        if(isset($_SESSION['deposit_last_order_id'])) {
            if($_SESSION['deposit_last_order_id'] < $response['data']['deposit_last_order_id']) {
                $hasNewOrder = true;
                $_SESSION['deposit_last_order_id'] = $response['data']['deposit_last_order_id'];
            }
        } else {
            $_SESSION['deposit_last_order_id'] = $response['data']['deposit_last_order_id'];
        }

        if(isset($_SESSION['withdraw_last_order_id'])) {
            if($_SESSION['withdraw_last_order_id'] < $response['data']['withdraw_last_order_id']) {
                $hasNewOrder = true;
                $_SESSION['withdraw_last_order_id'] = $response['data']['withdraw_last_order_id'];
            }
        } else {
            $_SESSION['withdraw_last_order_id'] = $response['data']['withdraw_last_order_id'];
        }
        $response['data']['hasNewOrder'] = $hasNewOrder;
    }

    return json_encode($response, JSON_UNESCAPED_UNICODE);
}

function update_vendor_image()
{
    $dataCounts = 0;
    $vendor_images = $data = $postData = array();
    $postData['group_id'] = $_POST['group_id'];
    foreach ($_POST as $key => $value) {
        $is_data = true;
        if (strpos($key, "show_name") !== false) {

            $data['vendor_id'] = str_replace('show_name', '', $key);
            $data['show_name'] = $value;
            $dataCounts++;
        } elseif (strpos($key, "image") !== false) {
            $data['image'] = $value;
            $dataCounts++;
        } elseif (strpos($key, "app_icon") !== false) {
            $data['app_icon'] = $value;
            $dataCounts++;
        } else {
            $is_data = false;
        }
        if (($dataCounts % 3) == 0 && $is_data) {
            $vendor_images[] = $data;
            $data = array();
        }
    }
    $postData['vendor_image_datas'] = $vendor_images;
    $result = curlPost('api/agent/update_vendor_image_settings', $postData);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function toggle_backend_maintenance()
{
    $data = array();
    $data['status'] = $_POST['toggle_backend_maintenance'];
    $result = curlPost('api/system_maintenance/backendMaintenanceToggleStatus', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function create_maintenance_whitelist()
{
    $data = array();
    $data['type'] = $_POST['type'];
    $data['value'] = $_POST['value'];
    $result = curlPost('api/system_maintenance/add', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function delete_maintenance_whitelist()
{
    $data = array();
    $data['id'] = $_POST['id'];
    $result = curlPost('api/system_maintenance/delete', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function get_drop_down_list()
{
    $data = array();
    $data['username'] = $_POST['username'];
    $result = curlPost('api/agent/newDropdownList', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function update_agent_group_permission()
{
    $data = $group_permission = array();
    $data['agent_id'] = $_POST['agent_id'];
    foreach ($_POST as $key => $val) {
        if(strpos($key, "group_id") !== false) {
            $group_permission[] = $val;
        }
    }
    $data['group_permission'] = $group_permission;
    $result = curlPost('api/agent/agent_group_permissions/update', $data);
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function save_backend_announcement()
{
    $data = array();
    $host_id = (count($_POST['host_id']) > 0) ? $_POST['host_id'] : [];
    $child_id = (count($_POST['child_id']) > 0) ? $_POST['child_id'] : [];
    $data['type_id'] = $_POST['type_id'];
    $data['announcement_content'] = $_POST['content'];

    $data['agent_ids'] = array_merge($host_id, $child_id);

    if ($_POST['id'] == '') {
        $result = curlPost('api/announcement/add', $data);
    } else {
        $data['id'] = $_POST['id'];
        $result = curlPost('api/announcement/edit', $data);
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function delete_announcement()
{
    $data = array();
    $data['id'] = $_POST['id'];

    $result = curlPost('api/announcement/delete', $data);

    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function update_agent_announcement_status()
{
    $data = array();
    $data['id'] = $_POST['id'];

    $result = curlPost('api/announcement/updateAgentAnnouncementStatus', $data);

    return json_encode($result, JSON_UNESCAPED_UNICODE);
}