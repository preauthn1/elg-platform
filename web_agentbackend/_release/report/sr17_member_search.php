<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../commonFunction.php';
require_once '../class/CreatePage.php';

//確認權限
if (!isset($check_auth['report/sr17_member_search'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$filter = array();
$w_jackpots = curlPost('api/dropdown/wjackpot', $filter);

$filter = array();
$filter['period_id'] = isset($_GET['period_id']) ? $_GET['period_id'] : 0;
if ($_GET[type] == 1) {
    $filter['agent_username'] = isset($_GET['username']) ? $_GET['username'] : 0;
} else {
    $filter['member_username'] = isset($_GET['username']) ? $_GET['username'] : 0;
}
$filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$filter['per_page'] = 20;
$record = curlPost('api/w_jackpot/member', $filter);
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
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['menu_report_sr17'] ?> > <?= $lang['menu_sr16_member_search'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="">
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-2" class="col-md-1 control-label">
                                            <?= $lang['period'] ?>
                                        </label>
                                        <div class="col-md-4">
                                            <select class="selectize" name="period_id" id="period_id">
                                                <?php
                                                foreach ($w_jackpots['data'] as $row) {
                                                    ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['period_id'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= str_replace('{N}', $row['no'], $lang['periods']) ?> - <?= $row['start_time']?> ~ <?= $row['end_time']?>
                                                    </option>
                                                    <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <label for="form-1-2" class="col-md-2 control-label">
                                            <select class="selectize" name="type" id="type">
                                                <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                    <?= $lang['member_account'] ?>
                                                </option>
                                                <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                    <?= $lang['agent_account'] ?>
                                                </option>
                                            </select>
                                        </label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control" name="username" id="username"
                                                   value='<?= $_GET['username'] ?>' />
                                        </div>
                                        <div class="col-md-3">
                                            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel (Maximum of 60,000)</button>
                                            <button class="btn btn-primary"><?= $lang['search'] ?></button>
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
                                        <th><?= $lang['agent_account'] ?></th>
                                        <th><?= $lang['member_account'] ?></th>
                                        <th><?= $lang['period'] ?></th>
                                        <th><?= $lang['lottery_ticket_no'] ?></th>
                                        <th><?= $lang['awards'] ?></th>
                                        <th class='text-right'><?= $lang['bonus'] ?></th>
                                        <th><?= $lang['status'] ?></th>
                                        <th><?= $lang['exchange_time'] ?></th>
                                        <th><?= $lang['time'] ?></th>
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
                                                        <?= $row['agent_username'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['member_username'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= str_replace('{N}', $row['no'], $lang['periods']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['ticket_number'] == '' ? '' : $row['ticket_number'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                    <?php
                                                    if ($row['w_jackpot_status'] == 0) {
                                                        echo $lang['no_draw'];
                                                    } else if ($row['prize'] == '') {
                                                        echo $lang['not_prize'];
                                                    } else  {
                                                        echo $lang[$row['prize']];
                                                    }
                                                    ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?php
                                                        if ($row['prize_type'] == 2) {
                                                            echo $row['point'];
                                                        } elseif ($row['point'] > 0) {
                                                            if ($_SESSION['toggle_thousands'] == 0) {
                                                                amount_thousands_off($row['point'], 2);
                                                            } else {
                                                                amount_thousands_on($row['point'], 2);
                                                            }
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?php
                                                        if ($row['reward_item_id'] == 0) {
                                                            echo '';
                                                        } else if ($row['status'] == 0) {
                                                            echo $lang['not_withdrawn'];
                                                        } else {
                                                            echo $lang['withdrawn'];
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['time'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['created_at'] ?>
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
        var period_id = $("#period_id").val();
        var type = $("#type").val();
        var username = $("#username").val();

        $.blockUI();
        window.location = 'ajax.php?action=sr17_member' +
            '&period_id=' + period_id +
            '&type=' + type +
            '&username=' + username;
        setTimeout($.unblockUI, 5000);
    });
});
</script>