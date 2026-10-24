<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['report/newsr11'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//取對應語言包
$translates = curlPost('api/translate');
$lab_v_world = isset($translates['data']['3009']) ? $translates['data']['3009'] : 'Reward';
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

//先取下拉選單用的值
$vendors = curlPost('api/vendor/list');

$game_type = curlPost('api/gameType/list', ['status' => 1]);
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['sr11'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-2 control-label">
                                                <select class="selectize" name="type">
                                                    <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['agent_account'] ?>
                                                    </option>
                                                    <option value="3" <?= ($_GET['type'] == 3) ? 'selected' : '' ?>>
                                                        <?= $lang['member_account'] ?>
                                                    </option>
                                                </select>
                                            </label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
                                            </div>

                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['platform'] ?></label>
                                            <div class="col-md-7">
                                                <select class="selectize" name="vendor_id[]" multiple="multiple">
                                                    <?php
                                                    foreach ($vendors['data'] as $row) {
                                                        $is_selected = (!in_array($row['id'], explode(',', SR11_NO_PRESELECTION)));
                                                    ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['vendor_id'] == '' && $is_selected) ? 'selected' : (in_array($row['id'], $_GET['vendor_id']) ? 'selected' : '') ?>>
                                                        <?= $row['name'] ?>&nbsp;
                                                    </option>
                                                    <?php } ?>
                                                    <option value="-1" <?= ((in_array(-1, $_GET['vendor_id']) || $_GET['vendor_id'] == '') ? 'selected' : '') ?>>
                                                        <?= $lab_v_world ?>&nbsp;
                                                    </option>
                                                    <option value="-2" <?= ((in_array(-2, $_GET['vendor_id']) || $_GET['vendor_id'] == '') ? 'selected' : '') ?>>
                                                        <?= $lab_reward ?>&nbsp;
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
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label">
                                                <?= $lang['game_type'] ?>
                                            </label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="game_type">
                                                    <option value="0"><?= $lang['all'] ?></option>
                                                    <?php foreach ($game_type['data'] as $row) { ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['game_type'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['name'] ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control date-picker" name="start_date"
                                                    value="<?= $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d 00:00', strtotime('-8hour')) ?>" />
                                            </div>
                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control date-picker" name="end_date"
                                                    value="<?= $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d 23:59', strtotime('+16hour')) ?>" />
                                            </div>
                                            <div class="col-md-5"></div>
                                            <div class="col-md-6">
                                                <a id="today" style="float:left"
                                                    class="btn btn-outline-info btn-sm"><?= $lang['today'] ?></a>
                                                <a id="yesterday" style="float:left"
                                                    class="btn btn-outline-info btn-sm"><?= $lang['yesterday'] ?></a>
                                                <a id="this_week" style="float:left"
                                                    class="btn btn-outline-info btn-sm"><?= $lang['this_week'] ?></a>
                                                <a id="last_week" style="float:left"
                                                    class="btn btn-outline-info btn-sm"><?= $lang['last_week'] ?></a>
                                                <a id="this_month" style="float:left"
                                                    class="btn btn-outline-info btn-sm"><?= $lang['this_month'] ?></a>
                                                <a id="last_month" style="float:left"
                                                    class="btn btn-outline-info btn-sm"><?= $lang['last_month'] ?></a>
                                            </div>
                                            <div class="col-md-1">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <input type="hidden" id="pay_fee_visible" name="pay_fee_visible" value="<?= $pay_fee_visible ?>" />
                                                <input type="hidden" id="sms_fee_visible" name="sms_fee_visible" value="<?= $sms_fee_visible ?>" />
                                                <input type="hidden" id="cost_visible" name="cost_visible" value="<?= $cost_visible ?>" />
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php /* 表格 總計 */ ?>
            <div class="row" id="total"></div>
            <?php /* 表格 明細 */ ?>
            <div class="row" id="detail"></div>
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

    $(".date-picker").daterangepicker({
        singleDatePicker: true,
        singleDatePicker: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerIncrement: 59,
        locale: {
            format: 'YYYY-MM-DD HH:mm'
        }
    });

    $('.selectize').selectize({
        plugins: ['remove_button'],
        create: false,
        dropdownParent: 'body'
    });

    $('#today').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-d 00:00', strtotime('+8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>");
    });

    $('#yesterday').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-d 00:00', strtotime('-1day +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-d 23:59', strtotime('-1day +8hour')) ?>");
    });

    $('#this_week').click(function(e) {
        $("[name='start_date']").val(
            "<?= date('Y-m-d 00:00', strtotime(- (date('N') - 1) . 'day +8hour')) ?>"
        );
        $("[name='end_date']").val(
            "<?= date('Y-m-d 23:59', strtotime((7 - date('N')) . 'day +8hour')) ?>"
        );
    });

    $('#last_week').click(function(e) {
        $("[name='start_date']").val(
            "<?= date('Y-m-d 00:00', strtotime(- (date('N') + 6) . 'day +8hour')) ?>"
        );
        $("[name='end_date']").val(
            "<?= date('Y-m-d 23:59', strtotime(- (date('N')) . 'day +8hour')) ?>"
        );
    });

    $('#this_month').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-01 00:00', strtotime(' +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-t 23:59', strtotime(' +8hour')) ?>");
    });

    $('#last_month').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-01 00:00', strtotime('-1month +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-t 23:59', strtotime('-1month +8hour')) ?>");
    });

    //下載總計
    $(document).on('click', '#excel', function(e) {
        $.blockUI();
        var param = getParam($(this).attr('data-type'), $(this).attr('data-username'));
        window.location = 'ajax.php' + param + '&action=newsr11';
        setTimeout($.unblockUI, 2000);
    });

    //下載明細
    $(document).on('click', '#excel_detail', function(e) {
        $.blockUI();
        var param = getParam($(this).attr('data-type'), $(this).attr('data-username'));
        window.location = 'ajax.php' + param + '&action=newsr11_detail';
        setTimeout($.unblockUI, 2000);
    });

    //下載會員
    $(document).on('click', '#excel_member', function(e) {
        $.blockUI();
        var param = getParam($(this).attr('data-type'), $(this).attr('data-username'));
        window.location = 'ajax.php' + param + '&action=newsr11_member';
        setTimeout($.unblockUI, 2000);
    });

    //代理
    $(document).on('click', '.agent_top', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        $.blockUI();
        var username = $(this).attr('data-username');
        var param = getParam(2, username)
        getDetail(param);
    });
    //代理
    $(document).on('click', '.agent_bottom', function(e) {
        $.blockUI();
        var username = $(this).attr('data-username');
        var param = getParam(2, username)
        getTotal(param);
        getDetail(param);
    });

    //上半部的表格點選會員
    $(document).on('click', '.member_top', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        $.blockUI();
        console.log('member_top', param);
        var username = $(this).attr('data-username');
        var param = getParam(2, username)
        getMember(param);
    });

    //下半部的表格點選會員
    $(document).on('click', '.member_bottom', function(e) {
        $.blockUI();
        var username = $(this).attr('data-username');
        var param = getParam(2, username)
        getTotal(param);
        getMember(param);
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
            var username = $('#dt-opt_info').attr('data-username');
            var param = getParam(2, username)
            param = param + '&page=' + page;
            getDetail(param);
        }
    });

    // AJAX獲取上方統計
    function getTotal(param) {
        $("#total").html('');
        $.get('newsr11_total.php' + param, function(data) {
            $("#total").html(data);
            $.unblockUI();
        });
    }

    // AJAX獲取下方明細
    function getDetail(param) {
        $("#detail").html('');
        $.get('newsr11_detail.php' + param, function(data) {
            $("#detail").html(data);
            $.unblockUI();
        });
    }

    // AJAX獲取下方明細-會員
    function getMember(param) {
        $.get('newsr11_member.php' + param, function(data) {
            $("#detail").html(data);
            $.unblockUI();
        });
    }

    function getParam(type, username) {
        var vendor_id = $("[name='vendor_id[]']").val();
        var game_type = $("[name='game_type']").val();
        var start_date = $("[name='start_date']").val();
        var end_date = $("[name='end_date']").val();
        var pay_fee_visible = $("[name='pay_fee_visible']").val();
        var sms_fee_visible = $("[name='sms_fee_visible']").val();
        var cost_visible = $("[name='cost_visible']").val();
        return '?vendor_id=' + vendor_id + '&game_type=' + game_type +
            '&type=' + type + '&username=' + username +
            '&start_date=' + start_date + '&end_date=' + end_date +
            '&pay_fee_visible=' + pay_fee_visible +
            '&sms_fee_visible=' + sms_fee_visible +
            '&cost_visible=' + cost_visible;
    }

    $.blockUI();
    var username = $("[name='username']").val();
    if ('<?= $_GET["type"] ?>' == 3) {
        var param = getParam(3, username)
        getMember(param);
    } else {
        var param = getParam(2, username)
        getTotal(param);
    }

});
</script>