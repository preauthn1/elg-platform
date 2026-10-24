<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['agent/edit']) && $_GET['id'] != $_SESSION['id']) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//新增頁，部分設置要從當前代理帶預設值
$self_query = array();
$self_query['id'] = $_SESSION['id'];
$self_query_result = curlPost('api/agent/view', $self_query);
$query_myself = null;

$mobile_validate_defaultValue =  1;

if ($self_query_result['code'] == 1000) {
    $query_myself = $self_query_result['data'];
    $mobile_validate_defaultValue = ($query_myself['mobile_validate'] == 1 || !isset($query_myself['mobile_validate'])) ?  1: 0;
}

//取得menu id
function getMenu($menus, &$owner_menus) {
    if (is_array($menus)) {
        foreach ($menus as $menu) {
            $owner_menus[] = $menu['id'];
            if (isset($menu['submenu'])) {
                getMenu($menu['submenu'], $owner_menus);
            }
        }
    }
}

$isEdit = isset($_GET['id']) ?  true : false;

// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/agent/view', $data);

    if ($result['code'] == 9003) {
        echo display_error_msg_html($lang['nopermission']);
        exit;
    }

    if ($result['code'] == 1000) {
        $agent = $result['data'];
        $mobile_validate_defaultValue = ($agent['mobile_validate'] == 1 || !isset($agent['mobile_validate'])) ?  1: 0;
    }
    // 查詢這個代理有的權限
    $data = array();
    $data['agent_id'] = $_GET['id'];
    $agent_menus = curlPost('api/agentMenu/list', $data);
    if ($agent_menus['code'] != 1000) {
        header("location:" . BASE_URL . "login.php");
    }
    if (is_array($agent_menus['data'])) {
        $owner_menus = array();
        getMenu($agent_menus['data'], $owner_menus);
    }
} else {
    //預設要給的權限
    $owner_menus = explode(',', DEFAULT_PERMISSIONS);
    //如果沒有帶ID,預設帶現在使用的後台帳號
    $agent_name = array();
    $agent_name['id'] = $_SESSION['host_id'];
    $agent_name = curlPost('api/agent/view', $agent_name);
}

//$agents = curlPost('api/agent/dropdownList');
//$agents = $agents['data'];

$is_advance_setting_permission = check_permission_menuCode('agent_advance_setting');
$is_edit_mobileFlag_permission = check_permission_menuCode('agent_edit_mobile_validate');
$is_edit_unlimited_permission = check_permission_menuCode('agent_edit_unlimited');


$filterDropdown = array();
$filterDropdown["organization_id"] = $agent["organization_id"];
$response_api = curlPost('api/agent/group/dropdownList', $filterDropdown);
$groups = $response_api['data'];

$groups_csutomized = array_filter($groups, function ($var) {
    return ($var['agent_group_type'] == 1);
});

$groups_cash_flow= array_filter($groups, function ($var) {
    return ($var['agent_group_type'] == 2);
});

$groups_sms= array_filter($groups, function ($var) {
    return ($var['agent_group_type'] == 3);
});

$groups_chat= array_filter($groups, function ($var) {
    return ($var['agent_group_type'] == 4);
});

$groups_vendor_customize= array_filter($groups, function ($var) {
    return ($var['agent_group_type'] == 5);
});

$isAgentGroupPermission = check_permission_menuCode('agent_group');
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>
                    <?= $lang['agnet_management'] ?> >
                    <?= (!isset($agent)) ? $lang['add_agent'] : $lang['edit_agent'] ?>
                </h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <form id="data-form">
                                    <div class="row col-md-11 ml-auto mr-auto">
                                        <div class="col-md-6 ml-auto mr-auto" style="float: left">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['is_sub_account'] ?> </label>
                                                        <div class="col-md-10">
                                                            <?php if (isset($agent)) { ?>
                                                            <label>
                                                                <?= ($agent['host_id'] > 0) ? $lang['sub_account'] : $lang['main_account'] ?>
                                                            </label>
                                                            <?php } else { ?>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="is_sub_account" value="1"
                                                                    id="s1">
                                                                <label for="s1"><?= $lang['option_yes'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="is_sub_account" value="0"
                                                                    id="s2" checked>
                                                                <label for="s2"><?= $lang['option_no'] ?></label>
                                                            </div>
                                                            <?php } ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label id='not_sub'><?= $lang['agent_previous'] ?></label>
                                                        <label id='is_sub'><?= $lang['main_account'] ?></label>
                                                        <?php if (isset($agent)) { ?>
                                                        <p><?= $agent['parent_username'] ?></p>
                                                        <?php } else { ?>
                                                        <select class="selectize-class" id="agent" name="parent"
                                                                onchange="getParentSetting(this.value);">
                                                            <option value="<?= $agent_name['data']['id'] ?>" selected>
                                                                <?= $agent_name['data']['username'] ?></option>
                                                        </select>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>
                                                            <?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?><?= $lang['username'] ?>
                                                        </label>
                                                        <?php if (isset($agent)) { ?>
                                                        <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                        <p><?= $agent['username'] ?></p>
                                                        <?php } else { ?>
                                                        <input type="text" placeholder="Account" name="username"
                                                            autocomplete="new-password" class="form-control">
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['nickname'] ?></label>
                                                        <input type="text" placeholder="Enter nickname"
                                                            class="form-control" name="nickname"
                                                            value="<?= $agent['nickname'] ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?= $lang['email'] ?></label>
                                                        <input type="email" placeholder="Enter email"
                                                            class="form-control" name="email"
                                                            autocomplete="new-password" value="<?= $agent['email'] ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class='password'>
                                                            <?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?><?= $lang['password'] ?>
                                                        </label>
                                                        <label class='new_password'>
                                                            <?= $lang['new_password'] ?>
                                                        </label>
                                                        <input type="password" placeholder="Password"
                                                            autocomplete="new-password" class="form-control"
                                                            name="password">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class='password'>
                                                            <?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?><?= $lang['password_confirm'] ?>
                                                        </label>
                                                        <label class='new_password'>
                                                            <?= $lang['new_password_confirm'] ?>
                                                        </label>
                                                        <input type="password" placeholder="Confirm Password"
                                                            class="form-control" name="password_confirmation">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['country'] ?></label>
                                                        <select class="selectize-class" name="country_id">
                                                            <?php foreach ($_SESSION['country'] as $key => $value) { ?>
                                                            <option value="<?= $key ?>"
                                                                <?= ($agent['country_id'] == $key) ? 'selected' : '' ?>>
                                                                <?= $value ?>
                                                            </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['city'] ?></label>
                                                        <input type="text" placeholder="City/Zone" class="form-control"
                                                            name="city" value="<?= $agent['city'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['address'] ?></label>
                                                        <input type="text" placeholder="Address" class="form-control"
                                                            name="address" value="<?= $agent['address'] ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['tel'] ?></label>
                                                        <input type="text" placeholder="Mobile" class="form-control"
                                                            name="mobile" value="<?= $agent['mobile'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <?php if ($agent['host_id'] == 0) { ?>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['percentage'] ?></label>
                                                        <input type="text" placeholder="18" class="form-control"
                                                            name="percentage" value="<?= $agent['percentage'] ?>"
                                                            <?= ($agent['parent'] != $_SESSION['id'] && $agent['id'] != '') ? 'disabled' : '' ?>>
                                                    </div>
                                                </div>
                                                <?php } else { ?>
                                                <input type="hidden" name="percentage"
                                                    value="<?= $agent['percentage'] ?>">
                                                <?php } ?>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['status'] ?></label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1" id="rad1"
                                                                    <?= ($agent['status'] == 1 || !isset($agent['status'])) ? 'checked' : '' ?>>
                                                                <label for="rad1"><?= $lang['enable'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0" id="rad2"
                                                                    <?= ($agent['status'] == '0') ? 'checked' : '' ?>>
                                                                <label for="rad2"><?= $lang['disable'] ?></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($agent['host_id'] == 0) { ?>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['email_certification'] ?></label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="email_validate" value="1"
                                                                    id="e1"
                                                                    <?= ($agent['email_validate'] == 1) ? 'checked' : '' ?>>
                                                                <label for="e1">
                                                                    <?= $lang['necessary'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="email_validate" value="0"
                                                                    id="e2"
                                                                    <?= ($agent['email_validate'] != 1) ? 'checked' : '' ?>>
                                                                <label for="e2"><?= $lang['unnecessary'] ?></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['mobile_authentication'] ?></label>
                                                        <div class="col-md-10">
                                                            <div class="<?= $is_edit_mobileFlag_permission ? "" : "disabledStyle" ?>">
                                                                <div class=" radio radio-inline">
                                                                    <input type="radio" name="mobile_validate" value="1"
                                                                        id="m1"
                                                                        <?= $is_edit_mobileFlag_permission ? "" : "disabled" ?>
                                                                        <?= ($mobile_validate_defaultValue == 1) ? 'checked' : '' ?>>
                                                                    <label for="m1"><?= $lang['necessary'] ?></label>
                                                                </div>
                                                                <div class="radio radio-inline">
                                                                    <input type="radio" name="mobile_validate" value="0"
                                                                        id="m2"
                                                                        <?= $is_edit_mobileFlag_permission ? "" : "disabled" ?>
                                                                        <?= ($mobile_validate_defaultValue == 0) ? 'checked' : '' ?>>
                                                                    <label for="m2"><?= $lang['unnecessary'] ?></label>
                                                                </div>
                                                                <?php if (!$is_edit_mobileFlag_permission) { ?>
                                                                <input type="hidden" name="mobile_validate"
                                                                    value="<?=$mobile_validate_defaultValue?>">
                                                                <?php } ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['member_allow_register'] ?></label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="frontend_register" value="1"
                                                                    id="r1"
                                                                    <?= ($agent['frontend_register'] == 1 || !isset($agent['frontend_register'])) ? 'checked' : '' ?>>
                                                                <label for="r1"><?= $lang['open'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="frontend_register" value="0"
                                                                    id="r2"
                                                                    <?= ($agent['frontend_register'] == '0') ? 'checked' : '' ?>>
                                                                <label for="r2"><?= $lang['notopen'] ?></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label><?= $lang['agent_mode_unlimit'] ?></label>
                                                            <div class="col-md-10">
                                                                <div class="<?= $is_edit_unlimited_permission ? "" : "disabledStyle" ?>">
                                                                    <div class="radio radio-inline">
                                                                        <input type="radio" name="agent_mode" value="1"
                                                                               id="agent_mode1"
                                                                            <?= ($is_edit_unlimited_permission) ? '' : 'disabled' ?>
                                                                            <?= ($agent['agent_mode'] != 0 || !isset($agent['agent_mode'])) ? 'checked' : '' ?>>
                                                                        <label for="agent_mode1"><?= $lang['open'] ?></label>
                                                                    </div>
                                                                    <div class="radio radio-inline">
                                                                        <input type="radio" name="agent_mode" value="0"
                                                                               id="agent_mode2"
                                                                            <?= ($is_edit_unlimited_permission) ? '' : 'disabled' ?>
                                                                            <?= ($agent['agent_mode'] == 0 && isset($agent['agent_mode'])) ? 'checked' : '' ?>>
                                                                        <label for="agent_mode2"><?= $lang['notopen'] ?></label>
                                                                    </div>
                                                                    <?php if (!$is_edit_unlimited_permission) { ?>
                                                                        <input type="hidden" class="1" name="agent_mode" id="agent_mode"
                                                                               value="<?= (is_array($agent) && isset($agent['agent_mode']) ? $agent['agent_mode'] : 1) ?>">
                                                                    <?php } ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                            </div>
                                            <!-- 有權限的人才看的到 -->
                                            <!-- 有權限的人才看的到 -->
                                            <!-- 有權限的人才看的到 -->
                                            <?php if ($is_advance_setting_permission && $isEdit) {  ?>
                                            <div class="setting-group-title">
                                                <?= $lang["menu_agent_advance_setting"] ?>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>
                                                            <?=$lang["EnableSetting_HasPC"]?>
                                                        </label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_pc" value="1" id="pc1"
                                                                    <?= ($agent['has_pc'] == 1) ? 'checked' : '' ?>>
                                                                <label for="pc1">
                                                                    <?= $lang["open"]?>
                                                                </label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_pc" value="0" id="pc2"
                                                                    <?= ($agent['has_pc'] != 1) ? 'checked' : '' ?>>
                                                                <label for="pc2">
                                                                    <?= $lang["notopen"]?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>
                                                            <?=$lang["EnableSetting_HasMobile"]?>
                                                        </label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_mobile" value="1"
                                                                    id="mob1"
                                                                    <?= ($agent['has_mobile'] == 1) ? 'checked' : '' ?>>
                                                                <label for="mob1">
                                                                    <?= $lang["open"]?>
                                                                </label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_mobile" value="0"
                                                                    id="mob2"
                                                                    <?= ($agent['has_mobile'] != 1) ? 'checked' : '' ?>>
                                                                <label for="mob2">
                                                                    <?= $lang["notopen"]?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>
                                                            <?=$lang["EnableSetting_HasApk"]?>
                                                        </label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_apk" value="1" id="apk1"
                                                                    <?= ($agent['has_apk'] == 1) ? 'checked' : '' ?>>
                                                                <label for="apk1">
                                                                    <?= $lang["open"]?>
                                                                </label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_apk" value="0" id="apk2"
                                                                    <?= ($agent['has_apk'] != 1) ? 'checked' : '' ?>>
                                                                <label for="apk2">
                                                                    <?= $lang["notopen"]?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>
                                                            <?=$lang["EnableSetting_HasIpa"]?>
                                                        </label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_ipa" value="1" id="ipa1"
                                                                    <?= ($agent['has_ipa'] == 1) ? 'checked' : '' ?>>
                                                                <label for="ipa1">
                                                                    <?= $lang["open"]?>
                                                                </label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_ipa" value="0" id="ipa2"
                                                                    <?= ($agent['has_ipa'] != 1) ? 'checked' : '' ?>>
                                                                <label for="ipa2">
                                                                    <?= $lang["notopen"]?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>
                                                            <?=$lang["EnableSetting_HasChat"]?>
                                                        </label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_chat" value="1" id="chat1"
                                                                    <?= ($agent['has_chat'] == 1) ? 'checked' : '' ?>>
                                                                <label for="chat1">
                                                                    <?= $lang["open"]?>
                                                                </label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_chat" value="0" id="chat2"
                                                                    <?= ($agent['has_chat'] != 1) ? 'checked' : '' ?>>
                                                                <label for="chat2">
                                                                    <?= $lang["notopen"]?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>
                                                            <?=$lang["EnableSetting_EnableBackendDeposit"]?>
                                                        </label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="agent_backend_transaction"
                                                                    value="1" id="abt1"
                                                                    <?= ($agent['agent_backend_transaction'] == 1) ? 'checked' : '' ?>>
                                                                <label for="abt1">
                                                                    <?= $lang["option_yes"]?>
                                                                </label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="agent_backend_transaction"
                                                                    value="0" id="abt2"
                                                                    <?= ($agent['agent_backend_transaction'] != 1) ? 'checked' : '' ?>>
                                                                <label for="abt2">
                                                                    <?= $lang["option_no"]?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php
                                            }
                                            ?>
                                            <!-- 有權限的人才看的到 -->
                                            <!-- 有權限的人才看的到 -->
                                            <!-- 有權限的人才看的到 -->
                                            <?php if ($isAgentGroupPermission && $isEdit) { ?>
                                            <div class="setting-group-title">
                                                <?= $lang["menu_agent_group"] ?>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['agent_group_type_1'] ?></label>
                                                        <select class="selectize-class"
                                                            name="customized_setting_group_id">
                                                            <?php foreach ($groups_csutomized as $item) { ?>
                                                            <option value="<?= $item["id"] ?>"
                                                                <?= ($agent['customized_setting_group_id'] ==$item["id"]) ? 'selected' : '' ?>>
                                                                <?=  $item["name"]  ?>
                                                            </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['agent_group_type_2'] ?></label>
                                                        <select class="selectize-class" name="cash_flow_group_id">
                                                            <?php
                                                            foreach ($groups_cash_flow as $item) {
                                                                ?>
                                                            <option value="<?= $item["id"] ?>"
                                                                <?= ($agent['cash_flow_group_id'] ==$item["id"]) ? 'selected' : '' ?>>
                                                                <?=  $item["name"]  ?>
                                                            </option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['agent_group_type_3'] ?></label>
                                                        <select class="selectize-class" name="sms_gateway_group_id">
                                                            <?php
                                                            foreach ($groups_sms as $item) {
                                                                ?>
                                                            <option value="<?= $item["id"] ?>"
                                                                <?= ($agent['sms_gateway_group_id'] ==$item["id"]) ? 'selected' : '' ?>>
                                                                <?=  $item["name"]  ?>
                                                            </option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['agent_group_type_4'] ?></label>
                                                        <select class="selectize-class" name="chat_group_id">
                                                            <?php
                                                            foreach ($groups_chat as $item) {
                                                                ?>
                                                            <option value="<?= $item["id"] ?>"
                                                                <?= ($agent['chat_group_id'] ==$item["id"]) ? 'selected' : '' ?>>
                                                                <?=  $item["name"]  ?>
                                                            </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['agent_group_type_5'] ?></label>
                                                        <select class="selectize-class" name="vendor_customize_group_id">
                                                            <?php
                                                            foreach ($groups_vendor_customize as $item) {
                                                                ?>
                                                                <option value="<?= $item["id"] ?>"
                                                                    <?= ($agent['vendor_customize_group_id'] ==$item["id"]) ? 'selected' : '' ?>>
                                                                    <?=  $item["name"]  ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php
                                            }
                                            } else {
                                                ?>
                                            <input type="hidden" name="email_validate"
                                                value="<?= $agent['email_validate'] ?>">
                                            <input type="hidden" name="mobile_validate"
                                                value="<?= $agent['mobile_validate'] ?>">
                                            <input type="hidden" name="frontend_register"
                                                value="<?= $agent['frontend_register'] ?>">
                                            <input type="hidden" name="agent_mode" id="agent_mode" value="<?= $agent['agent_mode'] ?>">
                                            <?php } ?>
                                        </div>
                                        <div class="col-md-4 ml-auto mr-auto" style="float: right">
                                            <div class="row">
                                                <label><?= $lang['permission_setting'] ?></label>
                                            </div>
                                            <?php
                                            foreach ($menus['data'] as $menu) {
                                                // 多層選單?>
                                            <input type="checkbox" id="<?= $menu['code'] ?>" name="agent_menus[]"
                                                value="<?= $menu['id'] ?>"
                                                <?= in_array($menu['id'], $owner_menus) ? 'checked' : '' ?>>
                                            <label for="<?= $menu['code'] ?>">
                                                <?= (isset($lang['menu_' . $menu['code']]) ? $lang['menu_' . $menu['code']] : $menu['code']) ?>
                                            </label><br>
                                            <?php
                                                if (isset($menu['submenu'])) {
                                                    foreach ($menu['submenu'] as $m) {
                                                        ?>
                                            &nbsp;&nbsp;&nbsp;&nbsp;
                                            <input type="checkbox" id="<?= $m['code'] ?>" name="agent_menus[]"
                                                value="<?= $m['id'] ?>" parent="<?= $m['parent'] ?>"
                                                <?= in_array($m['id'], $owner_menus) ? 'checked' : '' ?>>
                                            <label for="<?= $m['code'] ?>">
                                                <?= (isset($lang['menu_' . $m['code']]) ? $lang['menu_' . $m['code']] : $m['code']) ?>
                                            </label><br>
                                            <?php
                                                        if (isset($m['submenu'])) {
                                                            foreach ($m['submenu'] as $d) {
                                                                ?>
                                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                            <input type="checkbox" id="<?= $d['code'] ?>" name="agent_menus[]"
                                                value="<?= $d['id'] ?>" parent="<?= $d['parent'] ?>"
                                                <?= in_array($d['id'], $owner_menus) ? 'checked' : '' ?>>
                                            <label for="<?= $d['code'] ?>">
                                                <?= (isset($lang['menu_' . $d['code']]) ? $lang['menu_' . $d['code']] : $d['code']) ?>
                                            </label><br>
                                            <?php
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12 col-xs-6">
                                            <div class="text-right mrg-top-5">
                                                <input type="hidden" name="action" value="save_agent">
                                                <input type="button" class="btn btn-default"
                                                    value="<?= $lang['btn_back'] ?>" onclick="history.back();" />
                                                <button type="submit" class="btn btn-primary"
                                                    id="save"><?= $lang['btn_update'] ?></button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<?php
require_once '../_footer.php';
?>
<div class="modal fade" id="confirm_password_popup">
    <div class=" modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="popup-data-form">
                <div class="modal-body">
                    <div class="padding-15">
                        <div class="card-block">
                            <div class="pill-info nav-vertical nav-stacked">
                                <div class="tab-content">
                                    <div role="tabpanel" class="tab-pane fade in active" id="default">
                                        <div class="form-group">
                                            <p class="mrg-btm-15 font-size-13"><?= $lang['original_password'] ?></p>
                                            <input type="password" class="form-control" name="original_password">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="action" value="confirm_password">
            </form>
            <div class="modal-footer no-border">
                <div class="text-right">
                    <button class="btn btn-default btn-sm" data-dismiss="modal"><?= $lang['cancel'] ?></button>
                    <button class="btn btn-primary btn-sm" data-dismiss="modal"
                        id="confirm_password"><?= $lang['confirm'] ?></button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
$(document).ready(function() {

    if ("<?= $agent['host_id'] ?>" > 0) {
        $('#not_sub').hide();
    } else {
        $('#is_sub').hide();
    }

    if ("<?= isset($agent) ?>") {
        $('.password').hide();
    } else {
        $('.new_password').hide();
    }

    $("#save").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }

        if ("<?= (isset($agent) && $agent['id'] == $_SESSION['id']) ?>") {
            //修改自己的資料需要驗證密碼
            $('[name="original_password"]').val('');
            $('#confirm_password_popup').modal('show');
        } else {
            save();
        }
    });


    $("#confirm_password").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        })
        $.post("<?= BASE_URL ?>ajax.php", $("#popup-data-form").serialize(), function(data) {
            $.unblockUI();
            if (data.code == 1000) {
                save();
            } else {
                if (data.code == 2002) {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    });

    //權限增加同步勾選
    $('[name="agent_menus[]"]').click(function() {
        $('[parent="' + $(this).val() + '"').prop('checked', $(this).is(':checked'));
    });

    function save() {
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        });
        $.post("<?= BASE_URL ?>ajax.php", $("#data-form").serialize(), function(data) {
            $.unblockUI();
            if (data.code == 1000) {
                alert("<?= $lang["success"] ?>");
                if ("<?= $_GET['id'] ?>" == '') {
                    window.location = 'index.php';
                } else {
                    history.back();
                }
            } else if (data.code == '2002') {
                alert(data.data);
            } else {
                alert(data.message);
            }
        }, 'json');
    }
    $("[name='is_sub_account']").change(function(e) {
        var is_sub_account = $("input[name='is_sub_account']:checked").val();
        if (is_sub_account == 1) {
            $('#not_sub').hide();
            $('#is_sub').show();
        } else {
            $('#not_sub').show();
            $('#is_sub').hide();
        }
    });

    <?php if (!$isEdit) {?>
    getParentSetting(<?= $_SESSION['host_id'] ?>);
    <?php } ?>

    var _timer = {};
    function delay_till_last(id, fn, wait) {
        if (_timer[id]) {
            window.clearTimeout(_timer[id]);
            delete _timer[id];
        }
        return _timer[id] = window.setTimeout(function() {
            fn();
            delete _timer[id];
        }, wait);
    }

    $("#agent-selectized").on('keyup', function() {
        var val =  $("#agent-selectized").val();
        if(val.length >= 1){
            delay_till_last('agent', function() {
                $.post("<?= BASE_URL ?>ajax.php", {
                    action : 'get_drop_down_list',
                    username : val
                }, function(data) {
                    if (data.code == 1000) {
                        var $select = $('#agent').selectize();
                        var selectize = $select[0].selectize;
                        $.each( data.data, function( key, value ){
                            selectize.addOption({value: value['id'], text: value['username']});
                        })
                        selectize.refreshOptions();
                    }
                }, 'json');
            }, 1000);
        }
    });
});

function getParentSetting(agent_id) {
    if (agent_id > 0) {
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        });

        $.post("<?= BASE_URL ?>ajax.php", {
            action: "get_agent_detail",
            id: agent_id
        }, function (data) {
            $.unblockUI();
            if (data.code == 1000) {
                if (data.data.agent_mode == 1) {
                    $("#agent_mode1").prop("checked", true).trigger("click");
                    $("#agent_mode").val(1);
                } else {
                    $("#agent_mode2").prop("checked", true).trigger("click");
                    $("#agent_mode").val(0);
                }
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    }
}
</script>


<style>
.disabledStyle {
    opacity: .5;
}



.setting-group-title {
    font-size: 20px;
    color: #cd4e4e;
    font-weight: 700;
    border-bottom: solid 1px #EEE;
    margin-top: 25px;
    margin-bottom: 15px;
}
</style>