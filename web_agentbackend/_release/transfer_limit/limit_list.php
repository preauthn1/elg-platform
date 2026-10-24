<?php
require_once '../_init.php';

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
                                <h4 class="card-title"><?= $lang['adjustment_amount'] ?></h4>
                                <div class="form-group row">
                                    <input type="hidden" id="id"
                                           value="<?= $_POST["id"] ?>">
                                    <input type="hidden" id="memo"
                                           value="<?= $_POST["memo"] ?>">
                                    <label class="col-md-3 control-label"
                                           style="font-size: 20px"><?= $_POST['limit'] ?></label>
                                    <label class="col-md-2 control-label"
                                           style="font-size: 20px;color: <?= $_POST['type'] == "0" ? "green" : "red" ?>">
                                        <?= $_POST['type'] == "0" ? "+" : "-" ?></label>
                                    <label class="col-md-2 control-label"
                                           style="font-size: 20px;font-weight:bold;" id="amount"
                                           value="<?= $_POST['amount'] ?>"><?= $_POST['amount'] ?></label>
                                    <label class="col-md-2 control-label" style="font-size: 20px">=</label>
                                    <label class="col-md-3 control-label"
                                           style="font-size: 20px"><?= $_POST['result']?></label>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 col-xs-12">
                                    <input type="button" class="btn btn-primary" value="<?= $lang['btn_update'] ?>"
                                           onclick="addLimit(<?= $_POST['type'] ?>)"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script>
    function addLimit(type) {
        var id = $('#id').val();
        var amount = $('#amount').val();
        var type = type;
        var memo = $('#memo').val();
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        })
        $.post("<?= BASE_URL ?>ajax.php", {
            action: "update_transfer_limit",
            id: id,
            type: type,
            amount: amount,
            memo: memo
        }, function (data) {
            $.unblockUI();
            if (data.code == 1000) {
                window.location = "<?= BASE_URL ?>transfer_limit/index.php";
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    console.log(data);
                    alert(data.message);
                }
            }
        }, 'json');
    }

</script>

