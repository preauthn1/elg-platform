<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$filter = array();
$filter['agent_username'] = $_GET['agent_username'];
$filter['username'] = $_GET['username'];
$filter['start_time'] = ($_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d')) . ' 00:00:00';
$filter['end_time'] = ($_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d')) . ' 23:59:59';
$filter['page'] = $_GET['page'] ? $_GET['page'] : 1;
$filter['per_page'] = $_GET['per_page'] ? $_GET['per_page'] : 20;
$record = curlPost('api/unlimit/performance', $filter);
if ($agent['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
require_once '../_header.php';
require_once '../_menu.php';
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['menu_unlimit'] ?> > <?= $lang['menu_unlimit_performance'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <label for="form-1-1" class="col-md-1 control-label"><?= $lang['agent_account'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="agent_username" value='<?= $_GET['agent_username'] ?>' />
                                    </div>
                                    <label for="form-1-1" class="col-md-1 control-label"><?= $lang['member_account'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="username" value='<?= $_GET['username'] ?>' />
                                    </div>
                                </div>
                                <div class="form-group row">
                                    <label for="form-1-2" class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control datetime-picker"
                                            name="start_time"
                                            value="<?= $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d', strtotime('+8hour')) ?>" />
                                    </div>
                                    <label for="form-1-2"
                                        class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control datetime-picker" name="end_time"
                                            value="<?= $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d', strtotime('+8hour')) ?>" />
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
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="table-overflow">
                            <table class="table table-lg table-hover">
                                <thead>
                                <tr>
                                    <th><?= $lang['time'] ?></th>
                                    <th><?= $lang['kind'] ?></th>
                                    <th><?= $lang['agent_account'] ?></th>
                                    <th><?= $lang['member_account'] ?></th>
                                    <th class="text-right"><?= $lang['day_promote_sales'] ?></th>
                                    <th class="text-right"><?= $lang['day_direct_sales'] ?></th>
                                    <th class="text-right" style="padding-right: 2px"><?= $lang['reward'] ?></th>
                                    <th style="padding-left: 2px"><?= $lang['direct_promote'] ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                if (count($record['data']) > 0) {
                                    foreach ($record['data'] as $row) {
                                        ?>
                                        <tr>
                                            <td><?= $row['summary_date'] ?></td>
                                            <td><?= ($row['type'] == 1) ? $lang['agent'] : $lang['member'] ?></td>
                                            <td><?= $row['agent_username'] ?></td>
                                            <td><?= $row['username'] ?></td>
                                            <td class="text-right"><?= number_format($row['performance_direct'], 2) ?></td>
                                            <td class="text-right"><?= number_format($row['performance_team'], 2) ?></td>
                                            <td class="text-right" style="padding-right: 2px"><?= ($row['bonus_direct'] + $row['bonus_team']) ?></td>
                                            <td style="padding-left: 2px">(<?= number_format($row['bonus_direct'], 2) ?> + <?= number_format($row['bonus_team'], 2) ?>)</td>
                                        </tr>
                                        <?php
                                    }
                                } else { ?>
                                    <tr>
                                        <td colspan="7" align='center'>
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
        timePicker: false,
        locale: {
            format: 'YYYY-MM-DD'
        }
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