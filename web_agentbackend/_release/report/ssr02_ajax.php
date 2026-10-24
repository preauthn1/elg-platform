<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

$data = array();
if ($_GET['type'] == 1) {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 2) {
    $data['member_username'] = $_GET['username'];
} else if ($_GET['type'] == 3) {
    $data['member_nickname'] = $_GET['username'];
} else if ($_GET['type'] == 4) {
    $data['transaction_no'] = $_GET['username'];
} else if ($_GET['type'] == 5) {
    $data['execution_username'] = $_GET['username'];
}

$data['cash_flow_id'] = $_GET['cash_flow_id'] ? $_GET['cash_flow_id'] : "";
$data['source'] = $_GET['source'] ? $_GET['source'] : 0;
$data['filter'] = $_GET['filter'] ? $_GET['filter'] : 1;
$data['deposit_to'] = $_GET['deposit_to'] ? $_GET['deposit_to'] : 0;
$data['start_date'] = ($_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00')) . ':00';
$data['end_date'] = ($_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59')) . ':59';
$data['is_created_period'] = $_GET['is_created_period'] ? $_GET['is_created_period'] : 0;
$data['page'] = $_GET['page'] ? $_GET['page'] : 1;
$data['per_page'] = $_GET['per_page'] ? $_GET['per_page'] : 10;
$record = curlPost('api/report/transactionListNew', $data);
if ($record['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
} else if($record['code'] == '2002') {
    echo "<script>alert('". $record['message'] ."');</script>";
    exit();
}
?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <h4><?= $lang['detaillist'] ?></h4>
            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
            <div class="table-overflow">
                <table class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['num'] ?></th>
                            <th><?= $lang['ordernum'] ?></th>
                            <th><?= $lang['date'] ?></th>
                            <th><?= $lang['agent_account'] ?></th>
                            <th><?= $lang['deposit'] ?>/<?= $lang['withdrawal'] ?></th>
                            <th><?= $lang['agent_account_to'] ?></th>
                            <th><?= $lang['member_account'] ?></th>
                            <th class="text-right"><?= $lang['change_point'] ?></th>
                            <th class="text-right"><?= $lang['moment_point'] ?></th>
                            <th><?= $lang['source'] ?></th>
                            <th><?= $lang['execution_account'] ?></th>
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
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['id'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $row['transaction_no'] ?>
                                    </span>
                                    <?php
                                        if($row['third_party_order_no']) {
                                        ?>
                                        <br/><span style="color:#999;"><?= $row['third_party_order_no'] ?></span>
                                        <?php
                                        }
                                        ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $row['created_at'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span class="text-success">
                                        <b>
                                            <?= $row['agent_username'] ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $lang['transaction_type.'.$row['type']] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <?= $row['to_agent_username'] ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span class="<?= ($row['platform'] == 1) ? 'text-info' : 'text-success' ?>">
                                        <b>
                                            <?= $row['member_username'] ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                        amount_thousands_off($row['point']);
                                    } else {
                                        amount_thousands_on($row['point']);
                                    }?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                        amount_thousands_off($row['balance']);
                                    } else {
                                        amount_thousands_on($row['balance']);
                                    }?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['source'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['operator_username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['status'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['remark'] ?></span>
                                </div>
                            </td>
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