<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

//確認權限
if (!isset($check_auth['report/sr15'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//清空session
$_SESSION["agents_history"] = array();

//選單label
$vendors = curlPost('api/vendor/list');

//取對應語言包
$translates = curlPost('api/translate');
$lab_v_world = isset($translates['data']['3002']) ? $translates['data']['3002'] : 'V-World';
$lab_reward = isset($translates['data']['3003']) ? $translates['data']['3003'] : 'V-Reward';
$lab_jackpot = isset($translates['data']['3004']) ? $translates['data']['3004'] : 'Jackpot';
$lab_pay_fee = isset($translates['data']['3006']) ? $translates['data']['3006'] : 'Pay fees';
$lab_sms_fee = isset($translates['data']['3007']) ? $translates['data']['3007'] : 'SMS fee';
$lab_cost = isset($translates['data']['3008']) ? $translates['data']['3008'] : 'Cost';

//取代理設置
$setting = curlPost('api/agent/customized-setting');
$pay_fee_visible = 0;
$sms_fee_visible = 0;
$cost_visible = 0;
if ($setting['code'] == 1000) {
    foreach ($setting['data'] as $row) {
        if ($row['setting_key'] == 4053) {
            $pay_fee_visible = $row['value'];
        }
        if ($row['setting_key'] == 4054) {
            $sms_fee_visible = $row['value'];
        }
        if ($row['setting_key'] == 4055) {
            $cost_visible = $row['value'];
        }
    }
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
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['menu_report_sr15'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="">
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <div class="col-md-1">
                                            <label for="form-1-2" class="m-size control-label">
                                                <?= $lang['agent_account'] ?>
                                                <span style="color:#FF0000">*</span>
                                            </label>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control" name="username" id="username"
                                                    placeholder="<?= $lang["required_column"] ?>" value='' />
                                            <input type="hidden" class="form-control" name="parent_username"
                                                    id="parent_username" placeholder="" value='' />
                                        </div>
                                        <div class="col-md-1">
                                            <label for="form-1-2" class="m-size control-label">
                                                <?= $lang['platform'] ?>
                                            </label>
                                        </div>
                                        <div class="col-md-8">
                                            <select class="selectize" name="vendor_id[]" multiple="multiple">
                                                <?php
                                                foreach ($vendors['data'] as $row) {
                                                    $is_selected = (!in_array($row['id'], explode(',', SR11_NO_PRESELECTION)));
                                                    ?>
                                                <option value="<?= $row['id'] ?>" <?= $is_selected ? 'selected' : '' ?>>
                                                    <?= $row['name'] ?>
                                                </option>
                                                <?php } ?>
                                                <option value="-1" selected="selected" >
                                                    <?= $lab_v_world ?>
                                                </option>
                                                <option value="-2" selected="selected" >
                                                    <?= $lab_reward ?>
                                                </option>
                                                <?php
                                                if ($pay_fee_visible == 1) {
                                                    ?>
                                                    <option value="-3" <?= ((in_array(-3, $_GET['vendor_id']) || $_GET['vendor_id'] == '') ? 'selected' : '') ?>>
                                                        <?= $lab_pay_fee ?>&nbsp;
                                                    </option>
                                                    <?php
                                                }
                                                if ($sms_fee_visible == 1) {
                                                    ?>
                                                    <option value="-4" <?= ((in_array(-4, $_GET['vendor_id']) || $_GET['vendor_id'] == '') ? 'selected' : '') ?>>
                                                        <?= $lab_sms_fee ?>&nbsp;
                                                    </option>
                                                    <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="clear:both;"></div>
                                    <div class="form-group row">
                                        <div class="col-md-1">
                                            <label for="form-1-2" class="m-size control-label">
                                                <?= $lang['between'] ?>
                                            </label>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control datetime-picker m-size"
                                               name="start_time" id="start_time"
                                               value="<?= isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d 00:00') ?>" />
                                        </div>
                                        <div class="col-md-1">
                                            <label for="form-1-2" class="control-label">
                                                <?= $lang['to'] ?>
                                            </label>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control datetime-picker m-size"
                                                name="end_time" id="end_time"
                                                value="<?= isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d 23:59') ?>" />
                                        </div>
                                        <div class="col-md-1">
                                            <label for="form-1-2" class="control-label">
                                                <?= $lang['bet_is_zero'] ?>
                                            </label>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="radio radio-inline">
                                                <input type="radio" name="hidden_zero_data" value="1" id="rad1"
                                                    <?= ($_GET['hidden_zero_data'] == 1 || !isset($_GET['hidden_zero_data'])) ? 'checked' : '' ?>>
                                                <label for="rad1"><?= $lang['hidden'] ?></label>
                                            </div>
                                            <div class="radio radio-inline">
                                                <input type="radio" name="hidden_zero_data" value="0" id="rad2"
                                                    <?= ($_GET['hidden_zero_data'] == '0') ? 'checked' : '' ?>>
                                                <label for="rad2"><?= $lang['show'] ?></label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="hidden" id="cost_visible" name="cost_visible"
                                                   value="<?= $cost_visible ?>" />
                                            <button type="button" class="btn btn-primary" onclick="search()">
                                                <?= $lang['search'] ?>
                                            </button>
                                        </div>
                                        <div class="col-md-1"></div>
                                        <div class="col-md-11">
                                            <!--今日/昨日/本周/本月...-->
                                            <?php require_once '../global/six_types_datepicker.php'; ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- 表格 遊戲記錄-->
        <div class="row" id="result">
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

    $('.selectize').selectize({
        plugins: ['remove_button'],
        create: false,
        dropdownParent: 'body'
    });


    //換頁
    $(document).on('click', '.paginate_button', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var page = $(this).attr('data-dt-idx');
        var is_disable = $(this).hasClass('disabled');

        if (!is_disable) {
            loadTableData(page);
        }
    });

    $('#result').on('click', '#excel', function() {
        $.blockUI();
        var queryString = getQueryString(1);
        let url = 'ajax.php' + queryString + "&action=sr15_excel";

        window.location = url;
        setTimeout($.unblockUI, 5000);
    });
});

function search() {

    $("#parent_username").val('');
    loadTableData(0);
}

function loadTableData(page) {

    $.blockUI();

    var queryString = getQueryString(page);
    var url = 'sr15_ajax.php' + queryString;

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
}

function showAgentReportByUsername(username) {

    $("#username").val(username);
    $("#parent_username").val('');

    $.blockUI();

    var queryString = getQueryString(1);
    var url = 'sr15_ajax.php' + queryString;

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
}

function showChildReport(target_parent_username) {

    $.blockUI();

    $("#parent_username").val(target_parent_username);
    var queryString = getQueryString(1);
    var url = 'sr15_ajax.php' + queryString;

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
}

function getQueryString(page) {

    if (page == 0) {
        let pageValue = $(this).attr('data-dt-idx');;
        page = pageValue ? pageValue : 1;
    }

    let username = $("#username").val() ? $("#username").val() : '';
    let start_time = $("#start_time").val() ? $("#start_time").val() : '';
    let end_time = $("#end_time").val() ? $("#end_time").val() : '';
    let hidden_zero_data = $('input[name="hidden_zero_data"]:checked').val();
    var vendor_id = $("[name='vendor_id[]']").val();
    var cost_visible = $("#cost_visible").val();
    let parent_username = $("#parent_username").val();

    //如果搜尋 parent_username 就不搜尋 username
    if (!(parent_username === undefined) && parent_username != "") {
        username = "";
    }

    var queryString = "?page=" + page + "&per_page=" + 20 + "&vendor_id=" + vendor_id + "&username=" + username +
        "&parent_username=" + parent_username + "&start_time=" + start_time + "&end_time=" + end_time +
        "&hidden_zero_data=" + hidden_zero_data + "&cost_visible=" + cost_visible;

    return queryString;
}
</script>

<style>
form label {
    padding-top: 10px;
}

.form-group input {
    max-width: 350px;
}

.selectize-control {
    width: 80%;
}

.chipItem {
    cursor: pointer;
}
</style>