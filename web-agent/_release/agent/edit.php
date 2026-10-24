<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
//確認權限
if (!isset($check_auth['agent/edit']) && $_GET['id'] != $_SESSION['id']) {
    echo $lang['nopermission'];
    exit;
}
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/agent/view', $data);
    if ($result['code'] == 1000) {
        $agent = $result['data'];
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
        foreach ($agent_menus['data'] as $agent_menu) {
            $owner_menus[] = $agent_menu['id'];
            if (isset($agent_menu['submenu'])) {
                foreach ($agent_menu['submenu'] as $menu) {
                    $owner_menus[] = $menu['id'];
                }
            }
        }
    }
} else {
    //預設要給的權限
    $owner_menus = [7, 8, 11, 19, 20, 21, 22, 23, 24, 27, 28, 29, 32, 33, 34];
}
$data = array();
$data['include_sub_account'] = 0;
$data['include_self'] = 1;
$data['page'] = 1;
$data['per_page'] = 10000;
$agents = curlPost('api/agent/list', $data);
$agents = $agents['data'];

?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['agnet_management'] ?> ><?= (!isset($agent)) ? $lang['add_agent'] : $lang['edit_agent'] ?>
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
                                                            <label><?= ($agent['host_id'] > 0) ? $lang['sub_account'] : $lang['main_account'] ?></label>
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
                                                        <?php
                                                        if (isset($agent)) {
                                                        ?>
                                                        <p><?= $agent['parent_username'] ?></p>
                                                        <?php
                                                        } else {
                                                        ?>
                                                        <select class="selectize-class" name="parent">
                                                            <?php
                                                                foreach ($agents as $row) {
                                                                ?>
                                                            <option value=" <?= $row['id'] ?>"
                                                                <?= ($agent['parent'] == $row['id']) ? 'selected' : '' ?>>
                                                                <?= $row['username'] ?></option>
                                                            <?php
                                                                }
                                                                ?>
                                                        </select>
                                                        <?php
                                                        }
                                                        ?>

                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?><?= $lang['username'] ?></label>
                                                        <?php
                                                        if (isset($agent)) {
                                                        ?>
                                                        <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                        <p><?= $agent['username'] ?></p>
                                                        <?php
                                                        } else {
                                                        ?>
                                                        <input type="text" placeholder="Account" name="username"
                                                            class="form-control">
                                                        <?php
                                                        }
                                                        ?>
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
                                                            value="<?= $agent['email'] ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label
                                                            class='password'><?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?><?= $lang['password'] ?></label>
                                                        <label class='new_password'><?= $lang['new_password'] ?></label>
                                                        <input type="password" placeholder="Password"
                                                            class="form-control" name="password">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label
                                                            class='password'><?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?><?= $lang['password_confirm'] ?></label>
                                                        <label
                                                            class='new_password'><?= $lang['new_password_confirm'] ?></label>
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
                                                            <?php
                                                            foreach ($_SESSION['country'] as $key => $value) {
                                                            ?>
                                                            <option value="<?= $key ?>"
                                                                <?= ($agent['country_id'] == $key) ? 'selected' : '' ?>>
                                                                <?= $value ?></option>
                                                            <?php
                                                            }
                                                            ?>
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
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="mobile_validate" value="1"
                                                                    id="m1"
                                                                    <?= ($agent['mobile_validate'] == 1 || !isset($agent['mobile_validate'])) ? 'checked' : '' ?>>
                                                                <label for="m1"><?= $lang['necessary'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="mobile_validate" value="0"
                                                                    id="m2"
                                                                    <?= ($agent['mobile_validate'] == '0') ? 'checked' : '' ?>>
                                                                <label for="m2"><?= $lang['unnecessary'] ?></label>
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
                                                                <input type="radio" name="frontend_register" value="1" id="r1"
                                                                    <?= ($agent['frontend_register'] == 1 || !isset($agent['frontend_register'])) ? 'checked' : '' ?>>
                                                                <label for="r1"><?= $lang['open'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="frontend_register" value="0" id="r2"
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
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="agent_mode" value="1"
                                                                    id="agent_mode1"
                                                                    <?= ($agent['agent_mode'] != 0 || !isset($agent['agent_mode'])) ? 'checked' : '' ?>>
                                                                <label for="agent_mode1"><?= $lang['open'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="agent_mode" value="0"
                                                                    id="agent_mode2"
                                                                    <?= ($agent['agent_mode'] == 0 && isset($agent['agent_mode'])) ? 'checked' : '' ?>>
                                                                <label for="agent_mode2"><?= $lang['notopen'] ?></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php } ?>
                                        </div>
                                        <div class="col-md-4 ml-auto mr-auto" style="float: right">
                                            <div class="row">
                                                <label><?= $lang['permission_setting'] ?></label>
                                            </div>
                                            <?php
                                            foreach ($menus['data'] as $menu) {
                                            ?>
                                            <?php
                                                // 多層選單
                                                ?>
                                            <input type="checkbox" id="<?= $menu['code'] ?>"
                                                   name="agent_menus[]" value="<?= $menu['id'] ?>"
                                                <?= in_array($menu['id'], $owner_menus) ? 'checked' : '' ?>>
                                            <label for="<?= $menu['code'] ?>"><?= $lang['menu_' . $menu['code']] ?></label><br>
                                            <?php
                                                if (isset($menu['submenu'])) {
                                                    foreach ($menu['submenu'] as $menu) {
                                                ?>
                                            &nbsp;&nbsp;&nbsp;&nbsp;<input type="checkbox" id="<?= $menu['code'] ?>"
                                                name="agent_menus[]" value="<?= $menu['id'] ?>"
                                                parent="<?= $menu['parent'] ?>"
                                                <?= in_array($menu['id'], $owner_menus) ? 'checked' : '' ?>>
                                            <label
                                                for="<?= $menu['code'] ?>"><?= $lang['menu_' . $menu['code']] ?></label><br>
                                            <?php
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
    <!-- Content Wrapper END -->
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<!-- Page Container END -->
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
});
</script>