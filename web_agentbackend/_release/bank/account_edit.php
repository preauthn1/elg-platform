<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['bank/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// 取得下拉式選單銀行列表
$banks = curlPost('api/bank/dropdownList');
$banks = $banks['data'];

// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/bank/showAccount', $data);
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
                    > <?= $lang['edit_account'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <div class="col-md-12 ml-auto mr-auto">
                                    <div class="col-md-8 ml-auto mr-auto">
                                        <form id="data-form">
                                            <div class="col-md-12 mr-auto ml-auto">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label><?= $lang['bank_name'] ?></label>
                                                            <?php
                                                            if (isset($bank)) {
                                                                ?>
                                                                <input type="hidden" name="id"
                                                                       value="<?= $_GET['id'] ?>">
                                                                <p class="text-success"><?= $bank['bank_name'] ?></p>
                                                                <?php
                                                            } else {
                                                                ?>
                                                                <select class="selectize-class" name="bank">
                                                                    <?php
                                                                    foreach ($banks as $row) {
                                                                        ?>
                                                                        <option value="<?= $row['id'] ?>">
                                                                            <?= $row['bank_name'] ?></option>
                                                                        <?php
                                                                    }
                                                                    ?>
                                                                </select>
                                                                <?php
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label><?= $lang['account_name'] ?></label>
                                                        <input type="text" placeholder="Account"
                                                               class="form-control" name="account_name"
                                                               value="<?= $bank['account_name'] ?>">
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label><?= $lang['account_no'] ?></label>
                                                            <input type="text" placeholder="Account"
                                                                   class="form-control" name="account_no"
                                                                   value="<?= $bank['account_no'] ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label><?= $lang['min_amount'] ?></label>
                                                            <input type="text" placeholder="Account"
                                                                   class="form-control" name="min_amount"
                                                                   value="<?= $bank['min_amount'] ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label><?= $lang['max_amount'] ?></label>
                                                            <input type="text" placeholder="Account"
                                                                   class="form-control" name="max_amount"
                                                                   value="<?= $bank['max_amount'] ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label>
                                                            <label><?= $lang['status'] ?></label>
                                                        </label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1"
                                                                       id="rad1"
                                                                    <?= ($bank['status'] == 1) ? 'checked' : '' ?>>
                                                                <label for="rad1"><?= $lang['enable'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0"
                                                                       id="rad2"
                                                                    <?= ($bank['status'] != 1) ? 'checked' : '' ?>>
                                                                <label for="rad2"><?= $lang['disable'] ?></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-12 col-xs-6">
                                                        <input type="hidden" name="action" value="save_bank_account">
                                                        <input type="button" class="btn btn-default" value="<?=$lang['btn_back']?>"
                                                               onclick="history.back();" />
                                                        <button type="submit" class="btn btn-primary"
                                                                id="save"><?= $lang['btn_update'] ?></button>
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