<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

$data = array();
if ($_GET['type'] == 1) {
    $data['order_no'] = $_GET['username'];
} else if ($_GET['type'] == 2) {
    $data['member_username'] = $_GET['username'];
} else if ($_GET['type'] == 3) {
    $data['account_no'] = $_GET['username'];
}
// 狀態
if ($_GET['status'] != '' && isset($_GET['status'])) {
    $data['status'] = $_GET['status'];
}
// 查看下線
if (isset($_GET['id'])) {
    $data['id'] = $_GET['id'];
}

$data['start_time'] = isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d 00:00:00', strtotime('-16hour'));
$data['end_time'] = isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d 23:59:59', strtotime('+8hour'));

$data['page'] = $_GET['page'] ? $_GET['page'] : 1;
$data['per_page'] = 50;
$record = curlPost('api/order/getAutoDeposit', $data);
if ($record['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
$page = new CreatePage();

?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
            <div class="table-overflow">
                <table id="dt-opt" class="table table-lg table-hover">
                    <thead>
                    <tr>
                        <th><?= $lang['created_time'] ?> </th>
                        <th><?= $lang['ordernum'] ?> </th>
                        <th><?= $lang['bank_name'] ?> </th>
                        <th><?= $lang['bank_code'] ?> </th>
                        <th><?= $lang['account_name'] ?></th>
                        <th><?= $lang['account_no'] ?></th>
                        <th><?= $lang['member_account'] ?></th>
                        <th><?= $lang['amount'] ?></th>
                        <th><?= $lang['memo'] ?></th>
                        <th><?= $lang['order_status'] ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    if (count($record['data']) > 0) {
                        foreach ($record['data'] as $row) {
                            ?>
                            <tr>
                                <td>
                                    <div class="relative mrg-top-15">
                                        <span><?= $row['create_at'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                        <span><?= $row['order_no'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                    <span class="text-success"><b>
                                            <span><?= $row['bank_name'] ?></span>
                                        </b></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                        <!-- <span class="status away"> </span> -->
                                        <span><?= $row['bank_code'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                        <div class="info">
                                            <span><?= $row['account_name'] ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                        <span><?= $row['account_no'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span class="text-info"><b><?= $row['username'] ?></b></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['amount'], 2);
                                        } else {
                                            amount_thousands_on($row['amount'], 2);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= json_decode($row['remark'], true)['message'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <?php
                                        if ($row['status'] == 0) { ?>
                                            <?= $lang['order_create'] ?>
                                        <?php } elseif ($row['status'] == 1) { ?>
                                            <?= $lang['pending_review'] ?>
                                        <?php } elseif ($row['status'] == 2) { ?>
                                            <?= $lang['rejected'] ?>
                                        <?php } else { ?>
                                            <?= $lang['pass'] ?>
                                        <?php } ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    if ($row['status'] == 1) { ?>
                                        <div class="mrg-top-10 text-center">
                                            <button href="#" data-toggle="modal" data-target="#image"
                                                    data-uid="<?= $row['order_id'] ?>" id="review"
                                                    class="btn btn-primary"><?= $lang['review'] ?></button>
                                        </div>
                                    <?php } else { ?>
                                        <div class="mrg-top-10 text-center">
                                            <button href="#" data-toggle="modal" data-target="#image"
                                                    data-uid="<?= $row['order_id'] ?>" id="review"
                                                    class="btn btn-info"><?= $lang['order_info'] ?></button>
                                        </div>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                        <?php
                    } else { ?>
                        <tr>
                            <td colspan="12" align='center'>
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
<div class="modal fade" id="image">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="order_list">
        </div>
    </div>
</div>
<script>
    $(document).ready(function () {
        //換頁
        $(document).on('click', '.paginate_button', function (e) {
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

        //開啟審核視窗
        $(document).on('click', '#review', function (e) {
            //先清內容
            $("#order_list").html('');
            var id = $(this).attr('data-uid');
            $.post("order_detail.php", {
                'id': id,
            }, function (data) {
                $("#order_list").html(data);
            });
        });
    })
</script>