<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['withdrawal/bank_list'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/withdrawal/getBankInfo', $data);
    if ($result['code'] == 1000) {
        $bank = $result['data'];
    }
}
?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['menu_auto_deposit'] ?> > <?= $lang['bank_management'] ?>
                    > <?= $lang['edit_bank'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <div class="row">
                                    <div class="col-md-12 ml-auto mr-auto">
                                        <form id="data-form">
                                            <div class="col-md-12 mr-auto ml-auto">
                                                <div class="row">
                                                    <div class="col-md-1">
                                                        <label><?= $lang['bank_name'] ?></label>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <?php
                                                            if (isset($bank)) {
                                                                ?>
                                                                <input type="hidden" name="id"
                                                                       value="<?= $_GET['id'] ?>">
                                                                <?php
                                                            } ?>
                                                            <input type="text" class="form-control" name="bank_name"
                                                                   value="<?= $bank['bank_name'] ?>"/>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-1">
                                                        <label><?= $lang['bank_code'] ?></label>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <input type="text" class="form-control" name="bank_code"
                                                                   value="<?= $bank['bank_code'] ?>"/>
                                                        </div>
                                                    </div>
                                                    <label class="col-md-1">
                                                        <label><?= $lang['enable_status'] ?></label>
                                                    </label>
                                                    <div class="col-md-3">
                                                        <div class="radio radio-inline">
                                                            <input type="radio" name="status" value="1" id="rad1"
                                                                <?= ($bank['status'] == 1) ? 'checked' : '' ?>>
                                                            <label for="rad1"><?= $lang['enable'] ?></label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input type="radio" name="status" value="0" id="rad2"
                                                                <?= ($bank['status'] != 1) ? 'checked' : '' ?>>
                                                            <label for="rad2"><?= $lang['disable'] ?></label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-12 col-xs-6">
                                                        <div class="text-right mrg-top-5">
                                                            <input type="hidden" name="action"
                                                                   value="save_withdrawal_bank">
                                                            <input type="hidden" name="id" value="<?= $bank['id'] ?>">
                                                            <input type="button" class="btn btn-default"
                                                                   value="<?= $lang['btn_back'] ?>"
                                                                   onclick="history.back();"/>
                                                            <button type="submit" class="btn btn-primary"
                                                                    id="save"><?= $lang['btn_update'] ?></button>
                                                        </div>
                                                    </div>
                                                </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Content Wrapper END -->
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<!-- Page Container END -->
<?php
require_once '../_footer.php';
?>
<script>
    $(document).ready(function () {
        //儲存資料
        $("#save").click(function (e) {
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
            $.post("<?= BASE_URL ?>ajax.php", $("#data-form").serialize(), function (data) {
                $.unblockUI();
                if (data.code == 1000) {
                    history.back();
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