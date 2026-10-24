<?php
require_once '../_init.php';
$filter = array();
$filter['member_id'] = $_POST['member_id'];
$filter['start_time'] = $_POST['start_time'];
$filter['end_time'] = $_POST['end_time'];
$filter['page'] = isset($_POST['page']) ? $_POST['page'] : 1;
$filter['per_page'] = 1000;
$result = curlPost('api/member/flowLimit', $filter);
if ($result['code'] != 1000) {

}

?>
<div class="modal-body height-160">
	<a class="modal-close" href="" data-dismiss="modal" style="float: right;">
        <i class="ti-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title"><?= $lang['flow_limit'] ?></h4>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th><?= $lang['kind'] ?></th>
                                                <th><?= $lang['unlimit_bonus'] ?></th>
                                                <th><?= $lang['flow_limit'] ?></th>
                                                <th><?= $lang['valid_bet'] ?></th>
                                                <th><?= $lang['status'] ?></th>
                                                <th><?= $lang['time'] ?></th>
                                                <th><?= $lang['operating_time'] ?></th>
												<?php /*
                                                <th><?= $lang['operator'] ?></th>
                                                <th><?= $lang['remark'] ?></th>
                                                <th><?= $lang['function'] ?></th>
												*/ ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
										if (count($result['data']) > 0 && $result['code'] == 1000) {
											foreach ($result['data'] as $row) {
												?>
												<tr>
													<td class="<?=($row['status']==1)?'text-success':'text-danger';?>">
                                                        <?php
                                                        if ($row['status'] == 1) {
                                                            echo $lang['pass'];
                                                        } else {
                                                            echo $lang['no_pass'];
                                                        }
                                                        ?>
                                                    </td>
													<td><?= $row['bonus'] ?></td>
													<td><?= $row['flow_limit'] ?></td>
													<td><?= $row['valid_bet'] ?></td>
													<td><?= $lang['reward_'. $row['type']]?></td>
													<td><?= $row['created_at'] ?></td>
													<td><?= $row['updated_at'] ?></td>
													<?php /*
													<td><?=$row['admin']?></td>
													<td><?=$row['remark']?></td>
													<td>
													</td>
													*/?>
												</tr>
												<?php
											}
										}
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
