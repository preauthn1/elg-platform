<?php
require_once '../_init.php';

if ($_POST['id'] != 0) {
    $data = array();
    $data['id'] = $_POST['id'];
    $result = curlPost('api/country/view', $data);
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
                                <p class="mrg-btm-15 font-size-13"><?= $lang['country_name'] ?></p>
                                <input type="text" class="form-control" placeholder="Taiwan" name="name"
                                    value="<?= $data['name'] ?>">
                            </div>
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13"><?= $lang['country_code'] ?></p>
                                <input type="text" class="form-control" placeholder="TW" name="code"
                                    value="<?= $data['code'] ?>">
                            </div>
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13"><?= $lang['country_code_number'] ?></p>
                                <input type="text" class="form-control" placeholder="886" name="country_code"
                                    value="<?= $data['country_code'] ?>">
                            </div>
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13"><?= $lang['regular_expression'] ?></p>
                                <input type="text" class="form-control" placeholder="^[1-9]\d{7}$" name="phone_rule"
                                    value="<?= $data['phone_rule'] ?>">
                            </div>
                            <div class="form-group">
                                <p class="mrg-btm-15 font-size-13"><?= $lang['enable_status'] ?></p>
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
                                <p class="mrg-btm-15 font-size-13"><?= $lang['country_name'] ?></p>
                                <input type="text" class="form-control" placeholder="Taiwan"
                                    name="names[<?= $value['id'] ?>]" value="<?= $names[$value['id']] ?>">
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
    <input type="hidden" name="action" value="save_country">
</form>
<div class="modal-footer no-border">
    <div class="text-right">
        <button class="btn btn-default btn-sm" data-dismiss="modal"><?= $lang['cancel'] ?></button>
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
        });

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