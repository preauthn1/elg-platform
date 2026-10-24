<?php
require_once '../_init.php';

$data = array();
if ($_GET['type'] == 1) {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 2) {
    $data['member_username'] = $_GET['username'];
} else if ($_GET['type'] == 3) {
    $data['member_nickname'] = $_GET['username'];
} else if ($_GET['type'] == 4) {
    $data['transaction_no'] = $_GET['username'];
}
$data['source'] = $_GET['source'] ? $_GET['source'] : 0;
$data['filter'] = $_GET['filter'] ? $_GET['filter'] : 1;
$data['deposit_to'] = $_GET['deposit_to'] ? $_GET['deposit_to'] : 0;
$data['start_date'] = $start_date . ':00';
$data['end_date'] = $end_date . ':59';
$data['start_date'] = ($_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('+8hour'))) . ':00';
$data['end_date'] = ($_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+8hour'))) . ':59';
$agent = curlPost('api/report/transactionTotal', $data);
if ($agent['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
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
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['sr02_1'] ?></h4>
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
                                                    <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['agent_account'] ?></option>
                                                    <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['member_account'] ?>
                                                    <option value="3" <?= ($_GET['type'] == 3) ? 'selected' : '' ?>>
                                                        <?= $lang['member_nickname'] ?>
                                                    <option value="4" <?= ($_GET['type'] == 4) ? 'selected' : '' ?>>
                                                        <?= $lang['ordernum'] ?>
                                                    </option>
                                                </select>
                                            </label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
                                            </div>
                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control datetime-picker"
                                                    name="start_time"
                                                    value="<?= $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('+8hour')) ?>" />
                                            </div>
                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control datetime-picker" name="end_time"
                                                    value="<?= $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+8hour')) ?>" />
                                            </div>
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['source'] ?></label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="source">
                                                    <option value="0"><?= $lang['all'] ?></option>
                                                    <option value="1" <?= ($_GET['source'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['cs'] ?>
                                                    </option>
                                                    <option value="2" <?= ($_GET['source'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['onlinepay'] ?></option>
                                                </select>
                                            </div>
                                            <div class="col-md-1"></div>
                                            <div class="col-md-7">
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
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['filter'] ?></label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="filter">
                                                    <option value="1" <?= ($_GET['filter'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['agent_deposit'] ?>
                                                    </option>
                                                    <option value="2" <?= ($_GET['filter'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['all_members'] ?></option>
                                                </select>
                                            </div>

                                            <div class="col-md-2"><select class="selectize" name="deposit_to">
                                                    <option value="0" <?= ($_GET['deposit_to'] == 0) ? 'selected' : '' ?>>
                                                        <?= $lang['all'] ?>
                                                    </option>
                                                    <option value="1" <?= ($_GET['deposit_to'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['only_agent'] ?>
                                                    </option>
                                                    <option value="2" <?= ($_GET['deposit_to'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['only_member'] ?></option>
                                                </select></div>
                                            <div class="col-md-2"></div>
                                            <div class="col-md-1">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <!-- <input type="reset" class="btn btn-default" value="Clear" /> -->
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
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th class="text-right"><?= $lang['total_deposit'] ?></th>
                                            <th class="text-right"><?= $lang['total_withdrawl'] ?></th>
                                            <th class="text-right"><?= $lang['total_deposit'] ?>-<?= $lang['total_withdrawl'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $total_in = 0;
                                        $total_out = 0;
                                        foreach ($agent['data'] as $row) {
                                            $total_in += $row['total_in'];
                                            $total_out += $row['total_out'];
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <span><?= number_format($total_in, 2) ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <span><?= number_format($total_out, 2) ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <span><?= number_format(($total_in - $total_out), 2) ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 明細-->
            <div class="row" id="result"></div>
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
    //變更日期格式
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
        create: false,
        // sortField: {
        //     field: 'text',
        //     direction: 'asc'
        // },
        dropdownParent: 'body'
    });

    $('#today').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-d 00:00', strtotime('+8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>");
    });

    $('#yesterday').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-d 00:00', strtotime('-1day +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-d 23:59', strtotime('-1day +8hour')) ?>");
    });

    $('#this_week').click(function(e) {
        $("[name='start_time']").val(
            "<?= date('Y-m-d 00:00', strtotime(- (date('N') - 1) . 'day +8hour')) ?>"
        );
        $("[name='end_time']").val(
            "<?= date('Y-m-d 23:59', strtotime((7 - date('N')) . 'day +8hour')) ?>"
        );
    });
    $('#last_week').click(function(e) {
        $("[name='start_time']").val(
            "<?= date('Y-m-d 00:00', strtotime(- (date('N') + 6) . 'day +8hour')) ?>"
        );
        $("[name='end_time']").val(
            "<?= date('Y-m-d 23:59', strtotime(- (date('N')) . 'day +8hour')) ?>"
        );
    });
    $('#this_month').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-01 00:00', strtotime(' +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-t 23:59', strtotime(' +8hour')) ?>");
    });
    $('#last_month').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-01 00:00', strtotime('-1month +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-t 23:59', strtotime('-1month +8hour')) ?>");
    });

    //下載
    $(document).on('click', '#excel', function(e) {
        var type = $("[name='type']").val();
        var username = $("[name='username']").val();
        var sorurce = $("[name='source']").val();
        var filter = $("[name='filter']").val();
        var deposit_to = $("[name='deposit_to']").val();
        var start_time = $("[name='start_time']").val();
        var end_time = $("[name='end_time']").val();
        window.location = 'ajax.php?action=transactionList&type=' + type + '&username=' + username +
            '&source=' + sorurce + '&filter=' + filter + '&deposit_to=' + deposit_to + '&start_date=' + start_time + '&end_date=' + end_time;
    });

    // AJAX獲取代理明細
    function getAgentDetail(param) {
        $.get('sr02_agent_ajax.php' + param, function(data) {
            $("#result").html(data);
            $.unblockUI();
        });
    }

    // AJAX獲取會員明細
    function getDetail(param) {
        $.get('sr02_ajax.php' + param, function(data) {
            $("#result").html(data);
            $.unblockUI();
        });
    }

    $.blockUI();
    getDetail($(location).attr('search'));

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
            var result = $(location).attr('search').split('&page')[0]
            if (result == '') {
                param = '?page=' + page;
            } else {
                param = result + '&page=' + page;
            }
            getDetail(param);
        }
    });

});
</script>