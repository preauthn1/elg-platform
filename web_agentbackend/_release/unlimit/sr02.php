<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

//確認權限
if (!isset($check_auth['unlimit/sr02'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$filter = array();
$filter['username'] = $_GET['username'];
$filter['start_time'] = ($_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00')) . ':00';
$filter['end_time'] = ($_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59')) . ':59';
$filter['page'] = $_GET['page'] ? $_GET['page'] : 1;
$filter['per_page'] = $_GET['per_page'] ? $_GET['per_page'] : 10;
$record = curlPost('api/unlimit/transactionList', $filter);
if ($agent['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
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
                <h4><?= $lang['menu_unlimit'] ?> > <?= $lang['menu_unlimit_sr02'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <label for="form-1-1" class="col-md-1 control-label">
                                        <?= $lang['agent_account'] ?>
                                    </label>
                                    <div class="col-md-2">
                                        <input type="text" class="form-control" name="username" value='<?= $_GET['username'] ?>' />
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
                                    <div class="col-md-1">
                                        <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 總計-->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4><?= $lang['detaillist'] ?></h4>
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                    <tr>
                                        <th><?= $lang['num'] ?></th>
                                        <th><?= $lang['date'] ?></th>
                                        <th><?= $lang['member_account'] ?></th>
                                        <th><?= $lang['member_nickname'] ?></th>
                                        <th class="text-right"><?= $lang['change_point'] ?></th>
                                        <th class="text-right"><?= $lang['moment_point'] ?></th>
                                        <th><?= $lang['order_status'] ?></th>
                                        <th><?= $lang['memo'] ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if (count($record['data']) > 0) {
                                        foreach ($record['data'] as $row) {
                                            ?>
                                            <tr>
                                                <td><?= $row['id'] ?></td>
                                                <td><?= $row['created_at'] ?></td>
                                                <td><?= $row['username'] ?></td>
                                                <td><?= $row['nickname'] ?></td>
                                                <td class="text-right">
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($row['amount'], 2);
                                                    } else {
                                                        amount_thousands_on($row['amount'], 2);
                                                    }?>
                                                </td>
                                                <td class="text-right">
                                                    <?php
                                                    if ($row['to_limit_of_credit'] < 0) {
                                                        echo $lang['unlimited'];
                                                    } else {
                                                        if ($_SESSION['toggle_thousands'] == 0) {
                                                            amount_thousands_off(($row['to_limit_of_credit'] - $row['to_used_credit']), 2);
                                                        } else {
                                                            amount_thousands_on(($row['to_limit_of_credit'] - $row['to_used_credit']), 2);
                                                        }
                                                    }
                                                    ?>
                                                </td>
                                                <td><?= $row['status'] ?></td>
                                                <td><?= $row['remark'] ?></td>
                                            </tr>
                                            <?php
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="13" align='center'>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $lang['no_record'] ?></b></span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php }
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php
                            if (count($record['data']) > 0) {
                                $page = new CreatePage();
                                ?>
                                <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                                    <?= $lang['total'] ?> <?= $record['pagination']['total'] ?> <?= $lang['count'] ?>
                                </div>
                                <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                                    <?= $page->createPager($record['pagination']['pages'], $record['pagination']['current'], $lang) ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 明細-->
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
                window.location = $(location).attr('href') + '?page=' + page;
            } else {
                window.location = result + '&page=' + page;
            }
        }
    });
});
</script>