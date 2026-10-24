<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$filter = array();
$filter['agent'] = $_GET['agent'];
$filter['page'] = $_GET['page'] ? $_GET['page'] : 1;
$filter['start_time'] = $_GET['start_time'];
$filter['end_time'] = $_GET['end_time'];
$filter['per_page'] = 10;

$record = curlPost('api/unlimit/agentSearch', $filter);
if ($record['code'] != 1000) {
//header("location:" . BASE_URL . "login.php");
//exit();
}

require_once '../_header.php';
require_once '../_menu.php';
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
                <h4><?= $lang['menu_unlimit'] ?> > <?= $lang['menu_unlimit_agent_search'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <label class="col-md-1 control-label"><?= $lang['agent_account'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="agent" value="<?= $_GET['agent'] ?>" />
                                    </div>
                                    <label class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control datetime-picker" name="start_time"
                                               value="<?= $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('+8hour')) ?>" />
                                    </div>
                                    <label class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control datetime-picker" name="end_time"
                                               value="<?= $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+8hour')) ?>" />
                                    </div>
                                </div>
                                <div class="form-group row">
                                    <div class="col-md-5"></div>
                                    <div class="col-md-5">
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
                                    <div class="col-md-2">
                                        <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                        <input type="reset" class="btn btn-default clear" value="<?= $lang['clear'] ?>" />
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-3">
                                    <label class="col-md-12 control-label">
                                        <?= $lang['day_total_sales'] ?>:<?= number_format($record['data_summary']['performance'], 2) ?>
                                    </label>
                                </div>
                                <div class="col-md-3">
                                    <label class="col-md-12 control-label">
                                        <?= $lang['day_promote_sales'] ?>:<?= number_format($record['data_summary']['performance_direct'], 2) ?>
                                    </label>
                                </div>
                                <div class="col-md-3">
                                    <label class="col-md-12 control-label">
                                        <?= $lang['day_direct_sales'] ?>:<?= number_format($record['data_summary']['performance_team'], 2) ?>
                                    </label>
                                </div>
                                <div class="col-md-3">
                                    <label class="col-md-12 control-label">
                                        <?= $lang['reward'].$lang['direct_promote'] ?>:<?= number_format($record['data_summary']['bonus'], 2) ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['agent_account'] ?></th>
                                            <th class="text-right"><?= $lang['day_total_sales'] ?></th>
                                            <th class="text-right"><?= $lang['day_promote_sales'] ?></th>
                                            <th class="text-right"><?= $lang['day_direct_sales'] ?></th>
                                            <th class="text-right" style='padding-right: 2px;'><?= $lang['reward'] ?></th>
                                            <th style="padding-left: 2px;"><?= $lang['direct_promote'] ?></th>
                                            <th><?= $lang['createdate'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if (count($record['data']) > 0 ) {
                                        	foreach ($record['data'] as $row) {
                                        	?>
                                        	<tr>
                                        	    <td><?= $row['username'] ?></td>
                                                <td class="text-right"><?= number_format($row['performance_total'], 2) ?></td>
                                                <td class="text-right"><?= number_format($row['performance_direct'], 2) ?></td>
                                        	    <td class="text-right"><?= number_format($row['performance_team'],2) ?></td>
                                                <td class="text-right" style='padding-right: 2px;'><?= $row['bonus_total'] ?></td>
                                                <td style="padding-left: 2px;">(<?= $row['bonus_direct'] ?> + <?= $row['bonus_team'] ?>)</td>
                                        	    <td><?= $row['summary_date'] ?></td>
                                        	</tr>
                                        	<?php
                                        	}
                                        }
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
            				<?php 
            				}
            				?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require_once '../_bottom.php';
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

    //清除查詢資料
    $(".clear").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        window.location = 'agent_search.php';
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