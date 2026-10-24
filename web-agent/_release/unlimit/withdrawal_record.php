<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$filter = array();
$filter['member'] = $_GET['member'];
$filter['order_no'] = $_GET['order_no'];
$filter['start_time'] = $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('+8hour')) . ':00';
$filter['end_time'] = $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+8hour')) . ':59';
$filter['page'] = $_GET['page'] ? $_GET['page'] : 1;
$filter['per_page'] = 20;
$record = curlPost('api/unlimit/withdrawalRecord', $filter);

if ($record['code'] != 1000) {
//    header("location:" . BASE_URL . "login.php");
//    exit();
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
                <h4><?= $lang['menu_unlimit'] ?> > <?= $lang['menu_unlimit_record'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <label for="form-1-2"
                                        class="col-md-1 control-label"><?= $lang['member_account'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="member" value="<?=$_GET['member']?>" />
                                    </div>
                                    <label for="form-1-2"
                                        class="col-md-1 control-label"><?= $lang['withdraw_no'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="order_no" value="<?=$_GET['order_no']?>" />
                                    </div>
								</div>
								<div class="form-group row">
                                    <label for="form-1-2"
                                        class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control datetime-picker" name="start_time"
                                               value="<?= $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('+8hour')) ?>" />
                                    </div>
                                    <label for="form-1-2"
                                           class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control datetime-picker" name="end_time"
                                               value="<?= $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+8hour')) ?>" />
                                    </div>
								</div>
								<div class="form-group row">
                                    <div class="col-md-12">
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
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['withdraw_no'] ?></th>
                                            <th><?= $lang['member_account'] ?></th>
                                            <th class="text-right"><?= $lang['agent_availableapply_socre'] ?></th>
                                            <th class="text-right"><?= $lang['withdraw_amount'] ?></th>
                                            <th class="text-right"><?= $lang['remaining_reward_points'] ?></th>
                                            <th><?= $lang['status'] ?></th>
                                            <th><?= $lang['withdraw_application_time'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($record['code'] == 1000 && count($record['data']) > 0) {
                                        	foreach ($record['data'] as $row) {
                                        	?>
                                        <tr>
                                            <td><?= $row['id'] ?></td>
                                            <td><?= $row['username'] ?></td>
                                            <td class="text-right"><?= number_format($row['reward_point'], 2) ?></td>
                                            <td class="text-right"><?= number_format($row['amount'], 2) ?></td>
                                            <td class="text-right"><?= number_format($row['remaining_reward_points'], 2) ?></td>
                                            <td><?= ($row['type'] == 1) ? $lang['passed'] : $lang['rejected'] ; ?></td>
                                            <td><?= $row['created_at'] ?></td>
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
        window.location = 'withdrawal_record.php';
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