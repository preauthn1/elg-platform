<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['vendor_maintenances/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//$agents = curlPost('api/agent/dropdownList');
//$exclude_agents = $agents = $agents['data'];

$data = array();
$data['type'] = 1;
$queryVendors = curlPost('api/vendor/dropdownList', $data);

$targetID = isset($_GET['id']) ? $_GET['id'] : '';
$setting_status = 1;
$setting_start_time = date('Y-m-d 00:00', strtotime('+8hour'));
$setting_end_time = date('Y-m-d 00:00', strtotime('+32hour'));
$setting_vendor_id = '';
$setting_type = '';
$setting_agent_id = $_SESSION['host_id'];
$setting_agent_name = '';
$setting_exclude_agent_id = 0;
$setting_exclude_agent_name = '';

if (isset($_GET['id'])) {
    $post_data = array();
    $post_data['id'] = $_GET['id'];
    $query_exist = curlPost('api/vendor_maintenances/view', $post_data)["data"];
    $setting_status =  $query_exist["status"];
    $setting_start_time = date('Y-m-d H:i:s', strtotime( $query_exist["start_time"]. '+8hour'));
    $setting_end_time = date('Y-m-d H:i:s', strtotime( $query_exist["end_time"]. '+8hour'));   
    $setting_vendor_id = $query_exist["vendor_id"];
    $setting_type = $query_exist["type"];
    $setting_agent_id = $query_exist["agent_id"];
    $setting_agent_name = $query_exist["agent_name"];
    $setting_exclude_agent_id = $query_exist["exclude_agent_id"];
    $setting_exclude_agent_name = $query_exist["exclude_agent_name"];
} else {
    //如果沒有帶ID,預設帶現在使用的後台帳號
    $agent_name = array();
    $agent_name['id'] = $_SESSION['host_id'];
    $agent_name = curlPost('api/agent/view', $agent_name);
}
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['agnet_management'] ?> > <?= $lang['menu_vendor_maintenances'] ?></h4>
            </div>
            <div class="row" id="result">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <form id="data-form">
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['label_vendor'] ?></label>
                                        <div class="col-md-3">
                                            <select id="selectize-dropdown" name="vendor_id">
                                                <option value=""> <?=$lang["please_choose"] ?></option>
                                                <?php
                                                foreach ($queryVendors['data'] as $eachVendor) {
                                                    ?>
                                                <option value="<?=$eachVendor["id"] ?>"
                                                    <?= ($setting_vendor_id == $eachVendor["id"]) ? 'selected' : '' ?>>
                                                    <?=$eachVendor["name"] ?>
                                                </option>
                                                <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['type'] ?></label>
                                        <div class="col-md-3">
                                            <select id="selectize-dropdown" name="type">
                                                <option value=""> <?=$lang["please_choose"] ?>
                                                </option>
                                                <option value="1" <?= ($setting_type == 1) ? 'selected' : '' ?>>
                                                    <?= $lang['vendor_maintenances_type_1'] ?>
                                                </option>
                                                <option value="2" <?= ($setting_type == 2) ? 'selected' : '' ?>>
                                                    <?= $lang['vendor_maintenances_type_2'] ?>
                                                </option>
                                                <option value="3" <?= ($setting_type == 3) ? 'selected' : '' ?>>
                                                    <?= $lang['vendor_maintenances_type_3'] ?>
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['label_start_time'] ?> </label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control datetime-picker" name="start_time"
                                                value="<?= $setting_start_time ?>" />
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['label_end_time'] ?> </label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control datetime-picker" name="end_time"
                                                   value="<?= $setting_end_time ?>" />
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['maintenance_agent'] ?> </label>
                                        <div class="col-md-3">
                                            <select class="selectize-class" id="agent" name="agent_id">
                                                <?php if($_GET['id']) {?>
                                                    <option value="<?= $setting_agent_id ?>" selected>
                                                        <?= $setting_agent_name ?></option>
                                                <?php } else { ?>
                                                    <option value="<?= $agent_name['data']['id'] ?>" selected>
                                                        <?= $agent_name['data']['username'] ?>
                                                    </option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['exclude_agent'] ?> </label>
                                        <div class="col-md-3">
                                            <select class="selectize-class" id="exclude_agent" name="exclude_agent_id">
                                                <?php if($_GET['id'] && $setting_exclude_agent_id > 0) {?>
                                                    <option value="<?= $setting_exclude_agent_id ?>" selected>
                                                        <?= $setting_exclude_agent_name ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label" style="padding-top: 20px;">
                                            <?= $lang['enable_status'] ?>
                                        </label>
                                        <div class="col-md-3">
                                            <div class="radio radio-inline">
                                                <input type="radio" name="status" value="1" id="rad1"
                                                    <?= ($setting_status == 1) ? 'checked' : '' ?>>
                                                <label for="rad1"><?= $lang['enable'] ?></label>
                                            </div>
                                            <div class="radio radio-inline">
                                                <input type="radio" name="status" value="0" id="rad2"
                                                    <?= ($setting_status != 1) ? 'checked' : '' ?>>
                                                <label for="rad2"><?= $lang['disable'] ?></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-5 col-xs-6">
                                        <div class="text-right mrg-top-5">
                                            <input type="hidden" name="update_id" value="<?=$targetID ?>">
                                            <input type="hidden" name="action" value="save_vendor_maintenances">
                                            <button type="submit" class="btn btn-primary"
                                                id="save"><?= $lang['btn_update'] ?></button>
                                            <a href="index.php">
                                                <button type="button" class="btn"><?= $lang['btn_back'] ?></button>
                                            </a>
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
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<?php
require_once '../_footer.php';
?>
<script>
$(document).ready(function() {
    $('.datetime-picker').daterangepicker({
        singleDatePicker: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerIncrement: 1,
        locale: {
            format: 'YYYY-MM-DD HH:mm'
        }
    });

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

    $("#exclude_agent-selectized").on('keyup', function() {
        var val = $("#exclude_agent-selectized").val();
        if(val.length >= 1){
            delay_till_last('exclude_agent', function() {
                $.post("<?= BASE_URL ?>ajax.php", {
                    action : 'get_drop_down_list',
                    username : val
                }, function(data) {
                    if (data.code == 1000) {
                        var $select = $('#exclude_agent').selectize();
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

    $("#save").click(function(e) {
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
        $.post("<?= BASE_URL ?>ajax.php", $("#data-form").serialize(), function(data) {
            $.unblockUI();
            console.log(data);
            if (data.code == 1000) {
                alert(data.message);
                window.location = 'index.php';
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    });
});
</script>

<style>
.form-group label {
    padding-top: 10px;
}
</style>