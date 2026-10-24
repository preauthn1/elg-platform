<?php
require_once '../_init.php';

$data = array();

$order_id =  $_POST['order_id'];
$data['order_id'] = $_POST['order_id'];
$result = curlPost('api/withdrawal/payout/cash_flows', $data);

$cash_flows = $result['data'];

if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}


?>
<div class="modal-body height-160">

    <a class="modal-close" href="" data-dismiss="modal" style="float: right;">
        <i class="ti-close top-right-close"></i>
    </a>

    <div>

        <div class="text-c">

            <h4>
                <?= $lang["title_use_payout"]  ?>
                <div style="font-size: 12px;color: #AAA;">
                    <?= $lang["ordernum"].":".$_POST['order_id'] ?>
                </div>
            </h4>


            <?php if(count($cash_flows) <= 0) { ?>

            <div class="hintWarning">
                <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <?= $lang["msg_no_available_payout_vendors"] ?>

                <div style="font-size: 10px;color: #ef6b6b;">
                    <?= $lang["msg_amount_and_bank_not_support"] ?>
                </div>
            </div>

            <?php } else { ?>


            <div style="margin-top:20px;">
                <?= $lang["please_select_payout_vendor"] ?>
            </div>


            <div>

                <select id="agent_cash_flow_id" class="form-control" style="width: 250px;display: inline-block;">

                    <?php foreach ($cash_flows as $item) { ?>

                    <option value="<?= $item["agent_cash_flow_id"] ?>"><?= $item["name"] ?></option>

                    <?php }?>

                </select>

            </div>


            <div>
                <button class="btn btn-success" onclick="doPayoutProcess('<?= $order_id ?>')">
                    <?= $lang["submit"] ?>
                </button>
            </div>

            <?php } ?>

        </div>



    </div>



</div>


<style>
.hintWarning {
    font-size: 15px;
    padding: 20px 10px;
    background: #ffe5e5;
    color: #be4141;
    border-radius: 5px;
    margin-top: 15px;
    width: 350px;
    display: inline-block;
}
</style>


<script>
function doPayoutProcess(order_id) {

    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    });

    $agent_cash_flow_id = $("#agent_cash_flow_id").val();
    let postBody = "action=doPayoutProcess&order_id=" + order_id + "&agent_cash_flow_id=" + $agent_cash_flow_id;

    let url = "<?= BASE_URL ?>ajax.php";
    $.ajax({
        cache: false,
        dataType: 'json',
        data: postBody,
        url: url,
        type: 'POST',
        success: function(data) {
            $.unblockUI();
            if (data.code == 1000) {
                successAlert();
                closeAllModalAndReloadTable();
            } else {
                alert(data.message);
            }
        },
        error: function(err) {
            alert('error');
            $.unblockUI();
        }
    });


}
</script>