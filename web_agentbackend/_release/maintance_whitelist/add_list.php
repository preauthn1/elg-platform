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
                    <form class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title"><?= $lang['add'] . $lang['whitelist'] ?></h4>
                                <div class="table-overflow">
                                    <div class="row">
                                        <label class="col-md-2 control-label"><?= $lang['type'] ?>
                                        </label>
                                        <div class="col-md-4">
                                            <select id="select-agent" name="agent_group"
                                                    class="select-agent">
                                                <option value=""></option>
                                                <option value="1" selected>IP</option>
                                                <option value="2"><?= $lang['agent_account'] ?></option>
                                            </select>
                                        </div>
                                        <label class="col-md-2 control-label">
                                            <?= $lang['whitelist'] ?>
                                        </label>
                                        <div class="col-md-4">
                                            <input type="text" class="form-control" id="ip_value">
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
        var type = $('#select-agent').val();
        var value = $('#ip_value').val();
        console.log(type);
        console.log(value);
        $.post("<?= BASE_URL ?>ajax.php", {
            action: "create_maintenance_whitelist",
            type: type,
            value: value
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