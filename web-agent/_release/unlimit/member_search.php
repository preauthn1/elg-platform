<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$filter = array();
$filter['agent'] = $_GET['agent'];
$filter['member'] = $_GET['member'];
$filter['page'] = $_GET['page'] ? $_GET['page'] : 1;
$filter['per_page'] = 20;

$record = curlPost('api/unlimit/memberSearch', $filter);
if ($record['code'] != 1000) {
//header("location:" . BASE_URL . "login.php");
//exit();
}

require_once '../_header.php';
require_once '../_menu.php';
$page = new CreatePage();
?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['menu_unlimit'] ?> > <?= $lang['menu_unlimit_search'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <label for="form-1-2" class="col-md-1 control-label"><?= $lang['agent_account'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="agent" value="<?= $_GET['agent'] ?>" />
                                    </div>
                                    <label for="form-1-2" class="col-md-1 control-label"><?= $lang['member_account'] ?></label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="member" value="<?= $_GET['member'] ?>" />
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
                                            <th><?= $lang['agent_name'] ?></th>
                                            <th><?= $lang['member_account'] ?></th>
                                            <th><?= $lang['level'] ?></th>
                                            <th><?= $lang['identity'] ?></th>
                                            <th><?= $lang['unlimit_top_member'] ?></th>
                                            <th><?= $lang['unlimit_parent_member'] ?></th>
                                            <th><?= $lang['unlimit_promotions'] ?></th>
                                            <th class="text-right"><?= $lang['unlimit_sales']?></th>
                                            <th class="text-right"><?= $lang['agent_availableapply_socre'] ?></th>
                                            <th class="text-right"><?= $lang['agent_alreadyapply_socre'] ?></th>
                                            <th><?= $lang['createdate'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if (count($record['data']) > 0) {
                                        	foreach ($record['data'] as $row) {
                                        	?>
                                        	<tr <?php if($row['username'] == $_GET['member']){ echo 'class="table-primary"';} ?>>
                                                <td><?= $row['agent_name'] ?></td>
                                                <td><?= $row['username'] ?></td>
                                        	    <td><?= $row['agent_level'] ?></td>
                                        	    <td><?= ($row['member_direct_count'] == 0)? $lang['player'] : $lang['agent'] ?></td>
                                        	    <td><?= $row['first_username'] ?></td>
                                        	    <td><?= $row['topper_username'] ?></td>
                                        	    <td>
                                        	    	<a <?php if ($row['member_direct_count'] > 0) { echo 'href="member_search.php?agent='.$_GET['agent'].'&member='.$row['username'].'"';} ?>">
                                        	    		<?= $row['member_direct_count'] + $row['member_team_count'] ?>
                                        	    		(<?=$row['member_direct_count']?>+<?=$row['member_team_count']?>)
                                        	    	</a>
                                        	    </td>
                                        	    <td class="text-right"><?= number_format($row['performance_amount'], 2) ?></td>
                                        	    <td class="text-right"><?= number_format($row['bonus_amount'], 2) ?></td>
                                        	    <td class="text-right"><?= number_format($row['withdraw_amount'], 2) ?></td>
                                        	    <td><?= $row['reg_time'] ?></td>
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

    //清除查詢資料
    $(".clear").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        window.location = 'member_search.php';
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