<?php

require_once '../_init.php';
require_once '../commonFunction.php';

$data = array();

$data["id"] = $_GET["id"];
$data["start_time"] = $_GET["start_time"];
$data["end_time"] = $_GET["end_time"];
$data["page"] = 1;
$data["per_page"] = 1;

$result = curlPost('api/order/list', $data);
if ($result['code'] != 1000) {
     echo display_error_msg_html($result["message"]);
     exit;
}

 $row = $result["data"][0];
?>
<div class="row dash-style">
    <div class="col-md-6">
        <div class="label-text"><?= $lang["ordernum"] ?></div>
        <div class="value-text">
            <?= $row["order_no"] ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["ordernum"] ?> (<?= $lang["deposit_vendor_name"] ?>) </div>
        <div class="value-text">
            <?= $row["third_party_order_no"] ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["agent"] ?></div>
        <div class="value-text" title="<?= $row["agent_id"] ?>">
            <?= $row["agent_username"] ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["member"] ?></div>
        <div class="value-text" title="<?= $row["member_id"] ?>">
            <?= $row["member_username"] ?>
            <a href="/report/sr14.php?member_username=<?=$row["member_username"] ?>" class="link-text"
                style="margin-left:5px;" target="_blank">
                <?= $lang["menu_report_sr14"] ?>
            </a>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["deposit_vendor_name"] ?></div>
        <div class="value-text">
            <?= $row["cf_name"] ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["deposit_order_type_name"] ?></div>
        <div class="value-text">
            <?= $row["order_type_name"] ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["amount"] ?></div>
        <div class="value-text">
            <?php if ($_SESSION['toggle_thousands'] == 0) {
                amount_thousands_off($row['amount'], 2);
            } else {
                amount_thousands_on($row['amount'], 2);
            }?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["point"] ?></div>
        <div class="value-text">
            <?php if ($_SESSION['toggle_thousands'] == 0) {
                amount_thousands_off($row['point'], 2);
            } else {
                amount_thousands_on($row['point'], 2);
            }?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["time"] ?></div>
        <div class="value-text">
            <span><?= date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour')) ?></span>
        </div>
    </div>
    <div class="col-md-6">
        <div class="label-text"><?= $lang["status"] ?></div>
        <div class="value-text">
            <?= $row["status_name"]  ?>
        </div>
    </div>
    <div class="col-md-12">
        <div class="label-text"><?= $lang['memo'] ?></div>
        <div class="">
            <form id="save_manual_deposit_form">
                <input type="hidden" id="order_id" name='order_id' value="<?= $row['id'] ?>">
                <textarea class="form-control" id="remark" placeholder="<?=$lang['memo']?>"></textarea>
            </form>
        </div>
    </div>
    <div class="col-md-12">
        <div style="padding:25px;">
            <?php /* 狀態 0:建立,1:成功,2:失敗,3:己上分,4:待手動確認上分 */ ?>
            <?php if (in_array($row["status"], [0, 4])) { ?>
                <button class='btn btn-success' onclick="saveManualDeposit()">
                    <?= $lang["order_manual_deposit"] ?>
                </button>
            <?php } elseif (in_array($row["status"], [1, 2])) { ?>
                <button class='btn' onclick="resetStatusToPending()" style="margin-right:15px;">
                    <?= $lang["order_reset_status"] ?>
                </button>
            <?php } ?>
        </div>
    </div>
    <div style="clear:both"></div>
</div>
<script>
function saveManualDeposit() {
    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    });

    let form = "action=save_manual_deposit&" + $("#save_manual_deposit_form").serialize();

    $.post("<?= BASE_URL ?>ajax.php", form, function(data) {
        $.unblockUI();
        if (data.code == 1000) {
            successAlert();
            $("#previewManualDeposit").modal("hide");
            loadTableData(1);

        } else {
            alert(data.message);
        }
    }, 'json');
}

function resetStatusToPending() {
    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    });

    let form = "action=reset_order_status&" + $("#save_manual_deposit_form").serialize();

    $.post("<?= BASE_URL ?>ajax.php", form, function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            successAlert();
            $("#previewManualDeposit").modal("hide");
            loadTableData(1);

        } else {
            alert(data.message);
        }
    }, 'json');
}
</script>
<style>
.order_status_3 {
    color: #3f883e;
}

.order_status_2 {
    color: #FF0000;
</style>