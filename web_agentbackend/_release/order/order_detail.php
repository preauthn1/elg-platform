<?php
require_once '../_init.php';
require_once '../commonFunction.php';

$data = array();
$data['order_id'] = $_POST['id'];
$result = curlPost('api/order/getImage', $data);
$bank = $result['data'];
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
        <i class="ti-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                    <form class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title"><?= $lang['order_info'] ?></h4>
                                <div class="table-overflow">
                                    <table name="order_list" class="table table-striped">
                                        <tr>
                                            <th class="bg-gray" style="width:50%"><?= $lang['bank_name'] ?></th>
                                            <th class="bg-gray" style="width:50%"><?= $lang['bank_code'] ?></th>
                                        </tr>
                                        <tr>
                                            <input type="hidden" id="id"
                                                   value="<?= $bank['id'] ?>">
                                            <td><?= $bank['bank_name'] ?></td>
                                            <td><?= $bank['bank_code'] ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray"><?= $lang['account_name'] ?></th>
                                            <th class="bg-gray"><?= $lang['account_no'] ?></th>
                                        </tr>
                                        <tr>
                                            <td><?= $bank['account_name'] ?></td>
                                            <td><?= $bank['account_no'] ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray"><?= $lang['agent_name'] ?></th>
                                            <th class="bg-gray"><?= $lang['member_account'] ?></th>
                                        </tr>
                                        <tr>
                                            <td><?= $bank['agent_username'] ?></td>
                                            <td><?= $bank['username'] ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray"><?= $lang['real_name'] ?></th>
                                            <th class="bg-gray"><?= $lang['amount'] ?></th>
                                        </tr>
                                        <tr>
                                            <td><?= $bank['first_name'] ?></td>
                                            <td>
                                                <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                    amount_thousands_off($bank['amount'], 2);
                                                } else {
                                                    amount_thousands_on($bank['amount'], 2);
                                                }?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray"><?= $lang['createdate'] ?></th>
                                            <th class="bg-gray"><?= $lang['review_time'] ?></th>
                                        </tr>
                                        <tr>
                                            <td><?= $bank['create_at'] ?></td>
                                            <td>
                                                <?php if ($bank['status'] == 2 || $bank['status'] == 3) {
                                                    echo $bank['pay_time'];
                                                } else {
                                                    echo '-';
                                                } ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray"><?= $lang['operator'] ?></th>
                                            <th class="bg-gray"><?= $lang['status'] ?></th>
                                        </tr>
                                        <tr>
                                            <td>
                                                <?php if ($bank['status'] == 2 || $bank['status'] == 3) {
                                                    echo $bank['operator'];
                                                } else {
                                                    echo '-';
                                                } ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ($bank['status'] == 0) {
                                                    echo $lang['order_create'];
                                                } elseif ($bank['status'] == 1) {
                                                    echo $lang['pending_review'];
                                                } elseif ($bank['status'] == 2) {
                                                    echo $lang['rejected'];
                                                } else {
                                                    echo $lang['pass'];
                                                } ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray" colspan="2"><?= $lang['picture'] ?></th>
                                        </tr>
                                        <tr>
                                            <td colspan="2">
                                                    <input type="hidden" id="image_url" value="<?= $bank['image'] ?>">
                                                    <img onclick="clickImage()" src="<?= $bank['image'] ?>"
                                                         height="300">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="bg-gray" colspan="2"><?= $lang['memo'] ?></th>
                                        </tr>
                                        <tr>
                                            <td colspan="2">
                                                <?php
                                                if ($bank['status'] == 1) { ?>
                                                    <textarea class="form-control" id="remark"></textarea>
                                                <?php } else {
                                                    echo json_decode($bank['remark'], true)['message'];
                                                } ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php
                        if ($bank['status'] == 1) { ?>
                            <input type="button" class="btn btn-success" value="<?= $lang['pass'] ?>"
                                   onclick="reviewOrder(3)"/>
                            <input type="button" class="btn btn-danger" value="<?= $lang['reject'] ?>"
                                   onclick="reviewOrder(2)"/>
                        <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script>
    function reviewOrder(type) {
        var id = $('#id').val();
        var remark = $('#remark').val();
        var status = type;
        $.post("<?= BASE_URL ?>ajax.php", {
            action: "review_order",
            id: id,
            status: status,
            remark: remark
        }, function (data) {
            $.unblockUI();
            if (data.code == 1000) {
                window.location.reload()
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    }

</script>

