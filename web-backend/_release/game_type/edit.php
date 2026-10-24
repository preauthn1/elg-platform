<?php
require_once '../_init.php';
// 取類別
$data = array();
$data['status'] = 1;
$result = curlPost('api/gameType/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
$types = $result['data'];

if ($_POST['id'] != 0) {
    // 取詳細資料
    $data = array();
    $data['id'] = $_POST['id'];
    $result = curlPost('api/gameType/view', $data);
    if ($result['code'] != 1000) {
        if ($result['code'] > 9997) {
            header("location:" . BASE_URL . "login.php");
        } else {
            header('Location:' . $_SERVER['HTTP_REFERER']);
        }
        exit();
    }
    $data = $result['data'];
    if (is_array($data['names'])) {
        $names = array();
        foreach ($data['names'] as $row) {
            $names[$row['language_id']] = $row['name'];
        }
    }
}
?>
<form id="data-form">
    <div class="modal-body">
        <div class="padding-15">
            <div class="card-block">
                <div class="pill-info nav-vertical nav-stacked">
                    <ul class="nav nav-pills" role="tablist">
                        <li class="nav-item">
                            <a href="#default" class="nav-link active" role="tab" data-toggle="tab">預設</a>
                        </li>
                        <?php
                        foreach ($_LANGUAGES as $key => $value) {
                            ?>
                        <li class="nav-item">
                            <a href="#pills-vertical-<?= $value['id'] ?>" class="nav-link" role="tab"
                                data-toggle="tab"><?= $value['name'] ?></a>
                        </li>
                        <?php
                        }
                        ?>
                    </ul>
                    <div class="tab-content">
                        <div role="tabpanel" class="tab-pane fade in active" id="default">
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13">上層類別</p>
                                <select class="form-control" name="parent">
                                    <option value="0" <?= ($data['parent'] == 0) ? 'selected' : '' ?>>最上層</option>
                                    <?php
                                    foreach ($types as $type) {
                                        if ($type['parent'] == 0) {
                                            ?>
                                    <option value="<?= $type['id'] ?>"
                                        <?= ($data['parent'] == $type['id']) ? 'selected' : '' ?>><?= $type['name'] ?>
                                    </option>
                                    <?php
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13">類別名稱</p>
                                <input type="text" class="form-control" name="name" value="<?= $data['name'] ?>">
                            </div>
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13">啟用狀態</p>
                                <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                                    <input type="checkbox" name="status" id="toggle1"
                                        <?= ($data['status'] == 1) ? 'checked' : '' ?>>
                                    <label for="toggle1"></label>
                                </div>
                            </div>
                        </div>
                        <?php
                        foreach ($_LANGUAGES as $key => $value) {
                            ?>
                        <div role="tabpanel" class="tab-pane fade" id="pills-vertical-<?= $value['id'] ?>">
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13">類別名稱</p>
                                <input type="text" class="form-control" name="names[<?= $value['id'] ?>]"
                                    value="<?= $names[$value['id']] ?>">
                            </div>
                        </div>
                        <?php
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <input type="hidden" name="id" value="<?= $data['id'] ?>">
    <input type="hidden" name="action" value="save_game_type">
</form>
<div class="modal-footer no-border">
    <div class="text-right">
        <button class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
        <button class="btn btn-primary btn-sm" data-dismiss="modal" id="save">儲存</button>
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