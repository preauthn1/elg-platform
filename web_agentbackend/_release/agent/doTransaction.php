<?php
require_once '../_init.php';
//驗證是否有權限
if (!$_SESSION['has_transaction']) {
    echo '沒有權限';
    exit;
}

$type = $_POST['type']; //1:開分 2:洗分

//取這自己的餘額
$from = curlPost('api/agent/view', ['id' => $_SESSION['id']]);
if ($from['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
} else {
    $from = $from['data'];
}

//取這個人的餘額
$to = curlPost('api/agent/view', ['id' => $_POST['id']]);
if ($to['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
} else {
    $to = $to['data'];
}

?>
<form id="data-form">
    <div class="modal-body">
        <div class="padding-15">
            <div class="card-block">
                <div class="pill-info nav-vertical nav-stacked">
                    <div class="tab-content">
                        <div role="tabpanel" class="tab-pane fade in active" id="default">
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13">代理帳號：<?= $to['username'] ?></p>
                            </div>
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13">代理餘額：<?= $to['balance'] ?> </p>
                            </div>
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13"><?= ($type == 1) ? '開分' : '洗分' ?>點數</p>
                                <input type="text" class="form-control" name="point">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <input type="hidden" name="id" value="<?= $_POST['id'] ?>">
    <input type="hidden" name="type" value="<?= $type ?>">
    <input type="hidden" name="action" value="agent_transaction">
</form>
<div class="modal-footer no-border">
    <div class="text-right">
        <button class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
        <button class="btn btn-primary btn-sm" data-dismiss="modal" id="save"><?= $lang['btn_update'] ?></button>
    </div>
</div>
<script>
$(document).ready(function() {
    $("#save").click(function(e) {
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
        $.post("<?= BASE_URL ?>ajax.php", $("#data-form").serialize(), function(data) {
            $.unblockUI();
            if (data.code == 1000) {
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
});
</script>