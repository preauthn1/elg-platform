<?php
require_once '../_init.php';
require_once '../commonFunction.php';

$data = array();
$data['order_id'] = $_POST['id'];
$result = curlPost('api/withdrawal/getOrderInfo', $data);
$order = $result['data'];
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}

$currentStatusText = $lang["withdrawal_status_".$order["status"]];

$is_permission_withdrawal_order_mark_paid = check_permission_menuCode("withdrawal_order_mark_paid");
$is_withdrawal_order_payout = check_permission_menuCode("withdrawal_order_payout");
$is_withdrawal_order_success_to_waiting = check_permission_menuCode("withdrawal_order_success_to_waiting");
$is_withdrawal_order_payout_to_cancel = check_permission_menuCode("withdrawal_order_payout_to_cancel");

?>
<div class="modal-body height-160">
    <a class="modal-close" href="" data-dismiss="modal">
        <i class="ti-close top-right-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                    <form class="col-md-12">
                        <div class="">
                            <div class="card-block">
                                <h4 class="card-title">
                                    <?= $lang['order_info'] ?>
                                    <div style="font-size:12px;color: #AAA;">
                                        <?= $order['id'] ?>
                                    </div>
                                </h4>
                                <div class="table-overflow">
                                    <input type="hidden" id="id" value="<?= $order['id'] ?>">
                                    <div class="row dash-style">
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['bank_name'] ?></div>
                                            <div class="value-text"><?= $order['bank_name'] ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['bank_code'] ?></div>
                                            <div class="value-text"><?= $order['bank_code'] ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['account_name'] ?></div>
                                            <div class="value-text"><?= $order['account_name'] ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['account_no'] ?></div>
                                            <div class="value-text"><?= $order['account_no'] ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['agent_account'] ?></div>
                                            <div class="value-text"><?= $order['agent_username'] ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['member_account'] ?></div>
                                            <div class="value-text"><?= $order['username'] ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['real_name'] ?></div>
                                            <div class="value-text"><?= $order['real_name'] ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['amount'] ?></div>
                                            <div class="value-text">
                                                <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                    amount_thousands_off($order['amount'], 2);
                                                } else {
                                                    amount_thousands_on($order['amount'], 2);
                                                }?>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['createdate'] ?></div>
                                            <div class="value-text"><?= $order['created_at'] ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['review_time'] ?></div>
                                            <div class="value-text">
                                                <?php
                                                if ($order['status'] == 1 ) {
                                                    echo '-';
                                                } else {
                                                    echo $order['check_time'];                                                    
                                                } ?>

                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['operator'] ?></div>
                                            <div class="value-text">
                                                <span>
                                                    <?=$order["operator_name"] ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text"><?= $lang['status'] ?></div>
                                            <div class="value-text">
                                                <?php 
                                                    $langKey = "withdrawal_status_".$order['status']; 
                                                ?>
                                                <span>
                                                    <?=$lang[ $langKey] ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="label-text">
                                                <?= $lang['flow_limit'] ?>
                                            </div>
                                            <div>
                                                <?php /* click事件寫在 index.php */ ?>
                                                <input type="button" class="btn btn-warning float-left"
                                                       value="<?= $lang['watch_data'] ?>"
                                                       onclick="show_flow_limitModal('<?= $order["member_id"] ?>')" />
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="label-text"><?= $lang['memo'] ?></div>
                                            <div class="">
                                                <?php
                                                if ($order['status'] == 1) { ?>
                                                <textarea class="form-control" id="remark"
                                                    placeholder="<?=$lang['memo']?>"></textarea>
                                                <?php } else { ?>
                                                <div class="text-area">
                                                    <div><?=$order['remark'] ?></div>
                                                </div>
                                            </div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                    <?php
                                    if (is_array($order['detail'])) {
                                        if (count($order['detail']) > 0) {
                                        ?>
                                    <div class="row dash-style">
                                        <div class="col-md-12">
                                            <h4 class="card-title" style="">
                                                <?= $lang['label_payout_info'] ?>
                                            </h4>
                                        </div>
                                        <?php
                                        foreach($order["detail"] as $row) {
                                            $style = ($style == '' || $style == 'bg-white') ? 'bg-gray' : 'bg-white';
                                            ?>
                                            <div class="col-md-6 <?= $style ?>">
                                                <div class="label-text"><?= $lang['label_payout_vendor'] ?></div>
                                                <div class="value-text"><?= $row['cf_name'] ?></div>
                                            </div>
                                            <div class="col-md-6 <?= $style ?>">
                                                <div class="label-text"><?= $lang['status'] ?></div>
                                                <div class="value-text <?= ($row['is_success_payout'] == 1) ? 'text-success' : (($row['callback_time'] != "") ? 'text-danger' : '') ?>" >
                                                    <?= ($row['is_success_payout'] == 1 ? $lang['status_1'] : (($row['callback_time'] != "") ? $lang['status_0'] : '')) ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6 <?= $style ?>">
                                                <div class="label-text"><?= $lang['third_order_no'] ?></div>
                                                <div class="value-text"><?= $row['transaction_no'] ?></div>
                                            </div>
                                            <div class="col-md-6 <?= $style ?>">
                                                <div class="label-text"><?= $lang['third_ordernum'] ?></div>
                                                <div class="value-text"><?= $row['third_party_transaction_no'] ?></div>
                                            </div>
                                            <div class="col-md-6 <?= $style ?>">
                                                <div class="label-text"><?= $lang['label_third_party_timestamp'] ?></div>
                                                <div class="value-text"><?= $row['transaction_time'] ?></div>
                                            </div>
                                            <div class="col-md-6 <?= $style ?>">
                                                <div class="label-text"><?= $lang['label_third_party_callback_time'] ?>
                                                </div>
                                                <div class="value-text">
                                                    <?php if ($row['callback_time'] != null && $row['callback_time'] != "") { ?>
                                                        <?= date('Y-m-d H:i:s', strtotime($row['callback_time']. '+8hour')) ?>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                            <div class="col-md-12 <?= $style ?>">
                                                <div class="label-text"><?= $lang["memo"] ?></div>
                                                <div class="value-text"><?= $row['info_1'] ?></div>
                                            </div>
                                        <?php } ?>
                                    </div>
                                    <?php
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                </div>
                <?php if ($order['status'] == 1) { ?>
                <input type="button" class="btn btn-success" value="<?= $lang['pass'] ?>" onclick="reviewOrder(<?= $order['status'] ?>, 3)" />
                <input type="button" class="btn btn-danger" value="<?= $lang['reject'] ?>" onclick="reviewOrder(<?= $order['status'] ?>, 2)" />
                <?php
                    }
                    if ($order['status'] == 3 || $order['status'] == 29) {
                        //需要有權限
                        if ($is_permission_withdrawal_order_mark_paid) { ?>
                <button class="btn btn-success"
                    onclick="showWriteMemoMsgAndUpdateStatusModal('<?= $order['id'] ?>', '<?= $order['status'] ?>', '4', '<?= $lang["button_mark_as_paid"] ?>', '<?= $currentStatusText ?>', '<?= $lang["withdrawal_status_4"] ?>')">
                    <?= $lang["button_mark_as_paid"] ?>
                </button>
                <?php }

                        //需要有權限
                        if ($is_withdrawal_order_payout) { ?>
                <button class="btn btn-primary" onclick="showPayoutCashflows('<?= $order['id'] ?>')">
                    <?= $lang["title_use_payout"] ?>
                </button>
                <?php } ?>
                <button class="btn btn-danger"
                    onclick="showWriteMemoMsgAndUpdateStatusModal('<?= $order['id'] ?>', '<?= $order['status'] ?>', '9', '<?= $lang["button_cancel_and_refund"] ?>', '<?= $currentStatusText ?>', '<?= $lang["withdrawal_status_9"] ?>')">
                    <?= $lang["button_cancel_and_refund"] ?>
                </button>
                <?php
                    }

                    if($order['status'] == 4 ) {
                        if ($is_withdrawal_order_success_to_waiting) { ?>
                <button class="btn btn-danger"
                    onclick="showWriteMemoMsgAndUpdateStatusModal('<?= $order['id'] ?>', '<?= $order['status'] ?>', '3', '<?= $lang["button_force_status_to_wait_payment"] ?>', '<?= $currentStatusText ?>', '<?= $lang["withdrawal_status_3"] ?>')">
                    <?= $lang["button_force_status_to_wait_payment"] ?>
                </button>
                <?php
                        }
                    }

                    if($order['status'] == 21 || $order['status'] == 22) {
                        if ($is_withdrawal_order_payout_to_cancel) { ?>
                <button class="btn btn-danger"
                    onclick="showWriteMemoMsgAndUpdateStatusModal('<?= $order['id'] ?>', '<?= $order['status'] ?>', '3', '將狀態調整為 [等待付款] (工程師專用)', '<?= $currentStatusText ?>', '<?= $lang["withdrawal_status_3"] ?>')">
                    將狀態調整為 [等待付款] (工程師專用)
                </button>
                <?php }
                    } 
                ?>
            </div>
        </div>
    </div>
</div>
</div>
</div>
<script>
function reviewOrder(original_status, type) {
    var id = $('#id').val();
    var remark = $('#remark').val();
    var original_status = original_status;
    var status = type;

    var post_body = {
        action: "review_withdrawal_order",
        id: id,
        original_status: original_status,
        status: status,
        remark: remark
    };
    $.blockUI();
    $.post("<?= BASE_URL ?>ajax.php", post_body, function(data) {
        $.unblockUI();
        if (data.code == 1000) {
            closeAllModalAndReloadTable();
        } else {
            alert(data.message);
        }
    }, 'json');
}



function showPayoutCashflows(order_id) {

    $("#selectPayoutCashflowModal_Content").html('');
    $("#selectPayoutCashflowModal").modal("show");

    $.post("withdrawal_payout_select.php", {
        'order_id': order_id
    }, function(data) {
        $("#selectPayoutCashflowModal_Content").html(data);
    });

}


function showWriteMemoMsgAndUpdateStatusModal(orderID, targetOriginalStatus, targetStatus, operateTypeText,
                                              beforeStatusText, afterStatusText) {

    $("#targetOrderID").val(orderID);
    $("#targetOriginalStatus").val(targetOriginalStatus);
    $("#targetStatus").val(targetStatus);
    $("#operateType").text(operateTypeText);
    $("#memoMsg").val('');
    $("#display_order_id").text(orderID);

    $("#beforeStatusText").text(beforeStatusText);
    $("#afterStatusText").text(afterStatusText);

    $("#writeMemoMsgAndUpdateStatusModal").modal("show");
}
</script>


<style>


.text-area {
    position: relative;
    text-align: left;
    padding: 10px 10px 10px 20px;
    margin-top: 10px;
    max-width: 75%;
    background: #F7F7F7;
}

.text-area>div {

    white-space: pre-wrap;
}

.text-area>div {}


h4.card-title {
    font-weight: 700;
}
</style>