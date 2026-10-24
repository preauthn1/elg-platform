<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

if (isset($_GET['start_date'])) {
    $data = array();

    $data['username'] = $_GET['username'];
    $start_date = $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d', strtotime('-1day'));
    $end_date = $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d');

    $data['start_time'] = $start_date . ' 00:00:00' ;
    $data['end_time'] = $end_date . ' 23:59:59' ;
    $data['page'] = $_GET['page'] ? $_GET['page'] : 1;
    $data['per_page'] = 20;

    $client = new \target\resource\Clients\Client('internal');
    $result = $client->send('ReportSR07', 'GET', [], $data);
    if($result->statusCode != 200) {
        header("location:" . BASE_URL . "login.php");
        exit();
    }
    $record = (array) $result->data;
    $record['pagination'] = (array) $record['pagination'];
    //var_dump($result->data);
//    var_dump($record);
    //$record = curlPost('api/report/sr07', $data);

} ?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <h4><?= $lang['detaillist'] ?></h4>
            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
            <div class="table-overflow">
                <!-- <table id="dt-opt" class="table table-lg table-hover"> -->
                <table class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['num'] ?></th>
                            <th><?= $lang['date'] ?></th>
                            <th><?= $lang['send_account'] ?></th>
                            <th><?= $lang['accept_account'] ?></th>
                            <th><?= $lang['point'] ?></th>
                            <th><?= $lang['handling_fee'] ?></th>
                            <th><?= $lang['status'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (count($record['data']) > 0) {
                            foreach ($record['data'] as $row) {
                                $row = (array) $row;
                        ?>
                        <tr>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['id'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['created_at'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $row['self_username'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $row['other_username'] ?>
                                    </span>
                                </div>
                            </td>
<!--                            <td>-->
<!--                                <div class="mrg-top-15">-->
<!--                                --><?php //if ($row['type'] == 6) { ?>
<!--                                    <span>--><?//= $lang['send'] ?><!--</span>-->
<!--                                    --><?php //} elseif($row['type'] == 7) { ?>
<!--                                    <span>--><?//= $lang['receive'] ?><!--</span>-->
<!--                                    --><?php //} elseif($row['type'] == 8) { ?>
<!--                                    <span>--><?//= $lang['cancel'].'('.$lang['member'].')' ?><!--</span>-->
<!--                                    --><?php //} elseif($row['type'] == 9) { ?>
<!--                                    <span>--><?//= $lang['cancel'].'('.$lang['system'].')' ?><!--</span>-->
<!--                                    --><?php //} ?>
<!--                                </div>-->
<!--                            </td>-->
<!--                            <td>-->
<!--                                <div class="mrg-top-15">-->
<!--                                    --><?php //if ($row['type'] == 1) { ?>
<!--                                    <span style='color:red'>--><?//= number_format(-$row['point'], 2) ?><!--</span>-->
<!--                                    --><?php //} else { ?>
<!--                                    <span>--><?//= number_format($row['point'], 2) ?><!--</span>-->
<!--                                    --><?php //} ?>
<!--                                </div>-->
<!--                            </td>-->
                            <td>
                                <div class="mrg-top-15">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['amount']);
                                        } else {
                                            amount_thousands_on($row['amount']);
                                        }?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['tax']);
                                        } else {
                                            amount_thousands_on($row['tax']);
                                        }?>
                                </div>
                            </td>
<!--                            <td>-->
<!--                                <div class="mrg-top-15">-->
<!--                                    <span>--><?//= $row['operator'] ?><!--</span>-->
<!--                                </div>-->
<!--                            </td>-->
                            <td>
                                <div class="mrg-top-15">
                                    <?php if ($row['status'] == 0) { //1:未領取2:已領取3:已取消4:已過期 ?>
                                    <span class="text-danger"><?=$lang['failure']?></span>
                                    <?php } else if ($row['status'] == 4) { ?>
                                    <span class="text-danger"><?=$lang['expired']?></span>
                                    <?php } else if ($row['status'] == 2) { ?>
                                    <span class="text-success"><?=$lang['success_received']?></span>
                                    <?php } else if ($row['status'] == 3) { ?>
                                    <span class="text-muted"><?=$lang['cancel']?></span>
                                    <?php } else if ($row['status'] == 1) { ?>
                                    <span class="text-muted"><?=$lang['unaccalimed']?></span>
                                    <?php } ?>
                                </div>
                            </td>
                        </tr>
                        <?php
                            }
                        } else { ?>
                        <tr>
                            <td colspan="10" align='center'>
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
<script>
$(".refund").click(function(e) {
    if (e.preventDefault) {
        e.preventDefault();
    } else {
        window.event.returnValue == false;
    }
    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    })
    var uid = $(this).attr('data-uid');
    $.post("<?= BASE_URL ?>ajax.php", {
        "action": "refund_transaction",
        "uid": uid,
    }, function(data) {
        $.unblockUI();
        if (data.code == 1000) {
            alert(data.message);
            window.location.reload();
        } else {
            if (data.code == '2002') {
                alert(data.data);
            } else {
                alert(data.message);
            }
        }
    }, 'json');
});
</script>