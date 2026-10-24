<?php
require_once '../_init.php';
$data = array();
$result = curlPost('api/transfer_limit/getDropDownList', $data);
$agent_group = $result['data'];

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
                                <h4 class="card-title"><?= $lang['add_agent_transfer_limit'] ?></h4>
                                <div class="table-overflow">
                                    <div class="row">
                                        <label class="col-md-2 control-label"><?= $lang['group_name'] ?>
                                        </label>
                                        <div class="col-md-4">
                                            <select id="select-agent" name="agent_group"
                                                    class="select-agent">
                                                <option value=""></option>
                                                <?php
                                                foreach ($agent_group as $row) {
                                                    ?>
                                                    <option value="<?= $row['id'] ?>">
                                                        <?= $row['name'] ?>
                                                    </option>
                                                    <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <label class="col-md-2 control-label">
                                            <?= $lang['limit_amount'] ?>
                                        </label>
                                        <div class="col-md-4">
                                            <input type="text" class="form-control" id="limit">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="button" class="btn btn-success" value="<?= $lang['add'] ?>"
                               onclick="create()"/>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script>
    function create() {
        var id = $('#select-agent').val();
        var limit = $('#limit').val();
        $.post("<?= BASE_URL ?>ajax.php", {
            action: "create_transfer_limit",
            id: id,
            limit: limit
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

    $(document).ready(function () {
        $('#select-agent').selectize({
            create: true,
            sortField: 'text'
        });
    })
</script>