<?php
require_once '../_init.php';
//先取下拉選單用的值
$vendors = curlPost('api/vendor/list');
$game_type = curlPost('api/gameType/list', ['status' => 1]);
require_once '../_header.php';
require_once '../_menu.php';
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
                                                    <!-- <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                        總公司帳號
                                                    </option> -->
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
                                                    <?php foreach ($vendors['data'] as $row) {
                                                    ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['vendor_id'] == '' && 4 != $row['id']) ? 'selected' : (in_array($row['id'], $_GET['vendor_id']) ? 'selected' : '') ?>>
                                                        <?= $row['name'] ?></option>
                                                    <?php } ?>
                                                    <option value="-1"
                                                        <?= ((in_array(-1, $_GET['vendor_id']) || $_GET['vendor_id'] == '') ? 'selected' : '') ?>>
                                                        V-world</option>
                                                    <option value="-2"
                                                        <?= ((in_array(-2, $_GET['vendor_id']) || $_GET['vendor_id'] == '') ? 'selected' : '') ?>>
                                                        V-Reward</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2"
                                                class="col-md-2 control-label"><?= $lang['game_type'] ?></label>
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
                                                    value="<?= $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d 23:59', strtotime('-8hour')) ?>" />
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
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 總計-->
            <div class="row" id="total"></div>
            <!-- 表格 明細-->
            <div class="row" id="detail"></div>
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
        create: false,
        // sortField: {
        //     field: 'text',
        //     direction: 'asc'
        // },
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
        window.location = 'ajax.php' + param + '&action=sr11';
        setTimeout($.unblockUI, 2000);
    });

    //下載明細
    $(document).on('click', '#excel_detail', function(e) {
        $.blockUI();
        var param = getParam($(this).attr('data-type'), $(this).attr('data-username'));
        window.location = 'ajax.php' + param + '&action=sr11_detail';
        setTimeout($.unblockUI, 2000);
    });

    //下載會員
    $(document).on('click', '#excel_member', function(e) {
        $.blockUI();
        var param = getParam($(this).attr('data-type'), $(this).attr('data-username'));
        window.location = 'ajax.php' + param + '&action=sr11_member';
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
        console.log('agent_top', param);
        getDetail(param);
    });
    //代理
    $(document).on('click', '.agent_bottom', function(e) {
        $.blockUI();
        var username = $(this).attr('data-username');
        var param = getParam(2, username)
        console.log('agent_bottom', param);
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
        console.log('member_bottom', param);
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
        $.get('sr11_total.php' + param, function(data) {
            $("#total").html(data);
            $.unblockUI();
        });
    }

    // AJAX獲取下方明細
    function getDetail(param) {
        $("#detail").html('');
        $.get('sr11_detail.php' + param, function(data) {
            $("#detail").html(data);
            $.unblockUI();
        });
    }

    // AJAX獲取下方明細-會員
    function getMember(param) {
        $.get('sr11_member.php' + param, function(data) {
            $("#detail").html(data);
            $.unblockUI();
        });
    }

    function getParam(type, username) {
        var vendor_id = $(" [name='vendor_id[]']").val();
        var game_type = $("[name='game_type']").val();
        var start_date = $("[name='start_date']").val();
        var end_date = $("[name='end_date']").val();
        return '?vendor_id=' + vendor_id + '&game_type=' + game_type +
            '&type=' + type + '&username=' + username + '&start_date=' + start_date + '&end_date=' + end_date;
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