<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

if (isset($_GET['start_date'])) {
    $data = array();
    if ($_GET['type'] == 1) {
        $data['agent_username'] = $_GET['username'];
    } else if ($_GET['type'] == 2) {
        $data['member_username'] = $_GET['username'];
    } else if ($_GET['type'] == 3) {
        $data['member_nickname'] = $_GET['username'];
    } else if ($_GET['type'] == 4) {
        $data['transaction_no'] = $_GET['username'];
    }
    $data['start_date'] = $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d', strtotime('-1day'));
    $data['end_date'] = $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d');
    $data['status'] = $_GET['status'] ? $_GET['status'] : 0;
    $data['vendor_id'] = $_GET['vendor_id'] ? $_GET['vendor_id'] : 0;
    $data['page'] = $_GET['page'] ? $_GET['page'] : 1;
    $data['per_page'] = 50;
    $record = curlPost('api/report/sr06', $data);
    if ($record['code'] >= 9997) {
        header("location:" . BASE_URL . "login.php");
        exit();
    }
}

?>
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
                        <th><?= $lang['ordernum'] ?></th>
                        <th><?= $lang['date'] ?></th>
                        <th><?= $lang['agent_account'] ?></th>
                        <th><?= $lang['platform'] ?></th>
                        <th><?= $lang['type'] ?></th>
                        <th><?= $lang['member_account'] ?></th>
                        <th><?= $lang['member_nickname'] ?></th>
                        <th class="text-right"><?= $lang['change_point'] ?></th>
                        <th class="text-right"><?= $lang['moment_point'] ?></th>
                        <th><?= $lang['status'] ?></th>
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
                                        <span><?= $row['transaction_no'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['created_at'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['agent_username'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span class="text-info"><b><?= $row['vendor'] ?></b></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= ($row['type'] == 1 ? $lang['transferout'] : $lang['transferin']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span class="text-info"><b><?= $row['member_username'] ?></b></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['member_nickname'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            if ($row['type'] == 1) {
                                                amount_thousands_off(-$row['point'], 2, 'red');
                                            } else {
                                                amount_thousands_off(-$row['point']);
                                            }
                                        } else {
                                            if ($row['type'] == 1) {
                                                amount_thousands_on(-$row['point'], 2, 'red');
                                            } else {
                                                amount_thousands_on($row['point']);
                                            }
                                        } ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['balance']);
                                        } else {
                                            amount_thousands_on($row['balance']);
                                        } ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                    <span class="<?= ($row['status'] == 1000) ? 'text-success' : 'text-danger' ?>">
                                        <?= ($row['status'] == 1000) ? $lang['success'] : $lang['failure'] ?>
                                        <?= ($row['status'] == 9000 || $row['status'] == 9990) ? '(' . $lang['returned'] . ')' : '' ?>
                                    </span>
                                        <?php if ($row['status'] == 9995 and $row['point'] <= 1000 and $_SESSION['host_id'] == 33893) { ?>
                                            <a class="btn btn-outline-danger btn-sm refund"
                                               data-uid="<?= $row['id'] ?>"><?= $lang['compensate'] ?></a>
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
    $(".refund").click(function (e) {
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
        }, function (data) {
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