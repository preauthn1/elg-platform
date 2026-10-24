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
} else if ($_GET['type'] == 4) {
    $data['transaction_no'] = $_GET['username'];
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
$data['per_page'] = 20;
$record = curlPost('api/withdrawal/list', $data);
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
                            <th><?= $lang['created_time'] ?></th>
                            <th><?= $lang['ordernum'] ?></th>
                            <th><?= $lang['third_order_no'] ?></th>
                            <th><?= $lang['bank_name'] ?></th>
                            <th><?= $lang['bank_code'] ?></th>
                            <th><?= $lang['account_name'] ?></th>
                            <th><?= $lang['account_no'] ?></th>
                            <th><?= $lang['member_account'] ?></th>
                            <th class="text-r"><?= $lang['amount'] ?></th>
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
                                    <span><?= $row['order_id'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <?php
                                    $style = 'text-bold';
                                    $transactions = explode(',', $row['transaction_no']);
                                    foreach($transactions as $transaction) {
                                        $style = ($style == 'text-bold') ? '' : 'text-gray';
                                        ?>
                                        <div class="<?= $style ?>">
                                            <?= $transaction ?>
                                        </div>
                                    <?php } ?>
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
                                <div class="mrg-top-15  text-r">
                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                        amount_thousands_off($row['amount'], 2);
                                    } else {
                                        amount_thousands_on($row['amount'], 2);
                                    }?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <div class="overflow-text" title="<?= $row['remark'] ?>" style="width: 100px;">
                                        <span><?= $row['remark'] ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <?php 
                                    $langKey = "withdrawal_status_".$row['status']; 
                                    ?>
                                    <span class="<?= $langKey ?>">
                                        <?=$lang[ $langKey] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php
                                    if ($row['status'] == 1) { ?>
                                <div class="mrg-top-10 text-center">
                                    <button href="#" data-toggle="modal" data-target="#list_table"
                                        data-uid="<?= $row['order_id'] ?>" id="review"
                                        class="btn btn-primary"><?= $lang['review'] ?></button>
                                </div>
                                <?php } else { ?>
                                <div class="mrg-top-10 text-center">
                                    <button href="#" data-toggle="modal" data-target="#list_table"
                                        data-uid="<?= $row['order_id'] ?>" id="review"
                                        class="btn btn-info"><?= $lang['order_info'] ?></button>
                                </div>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php
                        }
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
            <div id="withdrawal_list_paging">
                <?php if (count($record['data']) > 0) { ?>
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

<div class="modal fade" id="list_table">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="withdrawal_list">
        </div>
    </div>
</div>

<div id="selectPayoutCashflowModal" class="modal fade" id="list_table">
    <div class="modal-dialog modal-lg" role="document" style="width:950px;min-width: 950px;">
        <div class="modal-content" style="min-height: 250px;margin-top: 10%;" id="selectPayoutCashflowModal_Content">
        </div>
    </div>
</div>

<div id="writeMemoMsgAndUpdateStatusModal" class="modal fade" id="list_table">
    <input type="hidden" id="targetOrderID">
    <input type="hidden" id="targetOriginalStatus">
    <input type="hidden" id="targetStatus">
    <div class="modal-dialog modal-lg" role="document" style="width:95%;max-width: 950px;">
        <div class="modal-content" style="min-height: 250px;margin-top: 5%;">
            <div class="modal-body height-160">
                <a class="modal-close" href="" data-dismiss="modal" style="float: right;">
                    <i class="ti-close top-right-close"></i>
                </a>
                <div class="text-c">
                    <h4>
                        <b>
                            <span id="operateType" style=""></span>
                            <span style="font-size:10px;margin-left:10px;color:#888;">
                                <?= $lang["ordernumber"] ?>:
                                <span id="display_order_id"></span>
                            </span>
                        </b>
                    </h4>
                    <div>
                        <span id="beforeStatusText"></span> =>
                        <span id="afterStatusText"></span>
                    </div>
                </div>
                <div class="text-l">
                    <?= $lang["please_write_memo_info"] ?>
                </div>
                <textarea id="memoMsg" class="form-control" placeholder="<?= $lang["memo"] ?>" style="height: 100px;"></textarea>
                <div class="text-c" style="margin-top:10px;">
                    <button class="btn btn-danger" onclick="writeMemoMsgAndUpdateStatus()">
                        <?= $lang["submit"] ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    //換頁
    $(document).on('click', '#withdrawal_list_paging .paginate_button', function(e) {
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
    $(document).on('click', '#review', function(e) {
        //先清內容
        $("#withdrawal_list").html('');
        var id = $(this).attr('data-uid');
        $.post("withdrawal_list.php", {
            'id': id,
        }, function(data) {
            $("#withdrawal_list").html(data);
        });
    });
})

function closeAllModalAndReloadTable() {
    $(".modal").modal("hide");
    $(".modal-backdrop").modal("hide");
    $('body').removeClass('modal-open');
    $('.modal-backdrop').remove();

    //刷新資料
    searchData();
}

function writeMemoMsgAndUpdateStatus() {
    let orderID = $("#targetOrderID").val();
    let original_status = $("#targetOriginalStatus").val();
    let status = $("#targetStatus").val();
    let remark = $("#memoMsg").val();

    let postBody = {
        action: "review_withdrawal_order",
        id: orderID,
        original_status: original_status,
        status: status,
        remark: remark
    };

    $.blockUI();
    $.post("<?= BASE_URL ?>ajax.php", postBody, function(data) {
        $.unblockUI();
        if (data.code == 1000) {
            closeAllModalAndReloadTable();
        } else {
            alert(data.message);
        }
    }, 'json');
}
</script>