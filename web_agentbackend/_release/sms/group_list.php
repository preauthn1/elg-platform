<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

$start_date = isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d H:i:s', strtotime('-1day'));
$end_date = isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d H:i:s');

$filter = array();
$filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$filter['per_page'] = 20;
$record = curlPost('api/sms/group_list', $filter);
if ($record['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
$page = new CreatePage();

?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['menu_report_sr14'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="">
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-2" class="col-md-1 control-label"><?= $lang['member_account'] ?>
                                            <span style="color:#FF0000">*</span>
                                        </label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control" name="member_username" id="member_username"
                                                placeholder="<?=$lang['required_column']?>" value='<?= $_GET['member_username'] ?>' />
                                        </div>
                                        <label for="form-1-2" class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control datetime-picker" name="start_time"
                                                id="start_time"
                                                value="<?= isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d 00:00') ?>" />
                                        </div>
                                        <label for="form-1-2" class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control datetime-picker" name="end_time"
                                                id="end_time"
                                                value="<?= isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d 23:59') ?>" />
                                        </div>
                                        <div class="col-md-3">
                                            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
                                            <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4"></div>
                                        <div class="col-md-8">
                                            <?php
                                                require_once '../global/six_types_datepicker.php';
                                            ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4></h4>
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                    <tr>
                                        <th><?= $lang['date'] ?></th>
                                        <th><?= $lang['agent_account'] ?></th>
                                        <th><?= $lang['member_account'] ?></th>
                                        <th class='text-right'><?= $lang['before_point'] ?></th>
                                        <th class='text-right'><?= $lang['change_point'] ?></th>
                                        <th class='text-right'><?= $lang['moment_point'] ?></th>
                                        <th><?= $lang['type'] ?></th>
                                        <th><?= $lang['status'] ?></th>
                                        <th><?= $lang['memo'] ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if (count($record['data']) > 0 && $record['code'] == 1000) {
                                        foreach ($record['data'] as $row) {
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['created_at'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['agent_username'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['member_username'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?= number_format($row['before_balance'], 2) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right <?=($row['point'] < 0)? 'text-danger': ''?>">
                                                        <?= number_format($row['point'], 2) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?= number_format($row['balance'], 2) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $lang['transaction_type.' . $row['type']] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 <?= ($row['status'] != 1000) ? 'text-danger font-weight-bold' : '' ?>"  >
                                                        <?= $row['status'] == 1000 ? $lang['status_1'] : $lang['status_0'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= ($row['type'] == 1 || $row['type'] == 2) ? $row['vendor_name'] : $row['remark'] ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="9" align='center'>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $lang['no_record']?></b></span>
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

    $('.selectize').selectize({
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
            var result = $(location).attr('search').split('&page')[0]
            if (result == '') {
                window.location = $(location).attr('href') + '?page=' + page;
            } else {
                window.location = result + '&page=' + page;
            }
        }
    });

    //下載
    $("#excel").on('click', function(e) {
        var member_username = $("#member_username").val();
        var start_time = $("#start_time").val();
        var end_time = $("#end_time").val();

        $.blockUI();
        window.location = 'ajax.php?action=sr14' +
            '&member_username=' + member_username +
            '&start_time=' + start_time +
            '&end_time=' + end_time;
        setTimeout($.unblockUI, 5000);
    });
});
</script>