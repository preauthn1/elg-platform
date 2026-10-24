<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['vendor_info/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/vendor_info/view', $data);

    if ($result['code'] == 1000) {
        $vendors = $result['data'];
        $vendor = array();
        $vendor['id'] = $_GET['id'];
        $vendor['vendor_id'] = $vendors['vendor_id'];
        $games = curlPost('api/vendor_info/getVendorGame', $vendor);
    }
}
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>
                    <?= $lang['menu_new_vendor'] ?> > <?= $lang['menu_vendor_info_management'] ?>
                    > <?= $lang['edit'] ?>
                </h4>
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
                                                            <label>
                                                                <?= $lang['label_vendor'] . $lang['id'] ?>
                                                            </label>
                                                            <?php
                                                            if (isset($vendors)) {
                                                                ?>
                                                                <input type="hidden" name="id"
                                                                       value="<?= $_GET['id'] ?>">
                                                                <input type="hidden" name="vendor_id"
                                                                       value="<?= $vendors['vendor_id'] ?>">
                                                                <p class="text-success">
                                                                    <?= $vendors['vendor_name'] ?>
                                                                </p>
                                                                <?php
                                                            } else {
                                                                ?>
                                                                <input type="text" placeholder="Account"
                                                                       class="form-control" name="vendor_id">
                                                                <?php
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label>
                                                            <?= $lang['vendor_info_name']?>
                                                        </label>
                                                        <input type="text" placeholder="Account"
                                                               class="form-control" name="name"
                                                               value="<?= $vendors['name'] ?>">
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>
                                                                <?= $lang['currency_code'] ?>
                                                            </label>
                                                            <input type="text" placeholder="Account"
                                                                   class="form-control" name="currency"
                                                                   value="<?= $vendors['currency'] ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>
                                                                <?= $lang['account_prefix'] ?>
                                                            </label>
                                                            <input type="text" placeholder="Account"
                                                                   class="form-control" name="account_prefix"
                                                                   value="<?= $vendors['account_prefix'] ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>
                                                                <?= $lang['rate'] ?>
                                                            </label>
                                                            <input type="text" class="form-control" name="rate"
                                                                    <?= (isset($_GET['id']) ? 'disabled' : '') ?>
                                                                         value="<?= floatval($vendors['rate']) ?>">
                                                            <?php if(isset($_GET['id'])) { ?>
                                                                <input type="hidden" class="form-control" name="rate"
                                                                       value="<?= floatval($vendors['rate']) ?>">
                                                            <?php } ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>
                                                                <?= $lang['transfer_min_amount'] ?>
                                                            </label>
                                                            <input type="text" class="form-control" name="transfer_min_amount"
                                                                   value="<?= floatval($vendors['transfer_min_amount']) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>
                                                                <?= $lang['prepared_account'] ?>
                                                            </label>
                                                            <input type="text" placeholder="Account"
                                                                   class="form-control" name="prepared_account_number"
                                                                   value="<?= $vendors['prepared_account_number'] ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label>
                                                            <?= $lang['status'] ?>
                                                        </label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1"
                                                                       id="rad1"
                                                                    <?= ($vendors['status'] == 1) ? 'checked' : '' ?>>
                                                                <label for="rad1">
                                                                    <?= $lang['enable'] ?>
                                                                </label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0"
                                                                       id="rad2"
                                                                    <?= ($vendors['status'] != 1) ? 'checked' : '' ?>>
                                                                <label for="rad2">
                                                                    <?= $lang['disable'] ?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-12">
                                                        <label>
                                                            <?= $lang['sms_gateway_info'] ?>
                                                        </label>
                                                        <div class="form-group">
                                                            <input type="hidden" name="type"
                                                                   value="<?= $vendors['type'] ?>">
                                                            <input type="hidden" name="created_at"
                                                                   value="<?= $vendors['created_at'] ?>">
                                                            <textarea class="form-control" name="info"><?= $vendors['info'] ?></textarea>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <?php
                                                    if (isset($vendors)) {
                                                        ?>
                                                        <label>
                                                            <?= $lang['show_game'] ?>
                                                        </label>
                                                        <div class="row">
                                                            <div class="radio radio-inline">
                                                                <input type="checkbox" id="all_checked" />
                                                            </div>
                                                            <div class="col-md-6" style="line-height: 40px;">
                                                                <?= $lang['select_all'] ?>
                                                            </div>
                                                        </div>
                                                    <?php
                                                    }

                                                    if (count($games['data']) > 0) {
                                                        $type_id = -1;
                                                        foreach ($games['data'] as $row) {
                                                            if ($type_id != $row['type_id']) {
                                                                if ($type_id != -1) {
                                                                    ?>
                                                                    </div>
                                                                    <?php
                                                                }
                                                                ?>
                                                                <hr />
                                                                <div class="row">
                                                                    <h4><b><?= $row['game_type_name'] ?></b></h4>
                                                                </div>
                                                                <div class="row">
                                                                <?php
                                                                $type_id = $row['type_id'];
                                                            }
                                                            ?>
                                                                <div class="col-md-3">
                                                                    <div class="radio radio-inline">
                                                                        <?php if ($row['status'] === null) { ?>
                                                                            <input type="checkbox"
                                                                                   data-type-id="<?= $row['type_id'] ?>"
                                                                                   name="newgame<?= $row['id'] ?>"
                                                                                   value="<?= $row['id'] ?>">
                                                                        <?php } else { ?>
                                                                            <input type="checkbox"
                                                                                   data-type-id="<?= $row['type_id'] ?>"
                                                                                   name="games<?= $row['id'] ?>"
                                                                                   value="<?= $row['id'] ?>"
                                                                                <?= ($row['status'] == 1) ? 'checked' : '' ?>>
                                                                        <?php } ?>
                                                                    </div>
                                                                    <?= $row['name'] ?>
                                                                </div>
                                                            <?php
                                                        }
                                                    }
                                                    ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <input type="hidden" name="action" value="save_vendor_info">
                                                    <input type="button" class="btn btn-default"
                                                           value="<?= $lang['btn_back'] ?>"
                                                           onclick="history.back();"/>
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
    <?php
    require_once '../_bottom.php';
    ?>
</div>
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
            });

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

        $("#all_checked").click(function () {
            if ($("#all_checked").prop("checked")) {//如果全選按鈕有被選擇的話（被選擇是true）
                $(":checkbox").each(function () {
                    $(this).prop("checked", true);//把所有的核取方框的property都變成勾選
                });
            } else {
                $(":checkbox").each(function () {
                    $(this).prop("checked", false);//把所有的核取方框的property都變成勾選
                });
            }
        });
    });
</script>