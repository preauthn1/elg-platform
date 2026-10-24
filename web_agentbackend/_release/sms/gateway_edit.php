<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['sms/gateway_edit'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// get all Country
$filter = array();
$countries = curlPost('api/country/dropdown');


// 取詳細資料
if ($_GET['id']) {
    $filter = array();
    $filter['id'] = $_GET['id'];
    $result = curlPost('api/sms/gateway/show', $filter);
    if ($result['code'] == 1000) {
        $sms = $result['data'];
        $_GET['country_id'] = explode(',', $sms['country_id']);
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
                <h4><?=$lang['sms_management']?> > <?=$lang['sms_gateway_list']?> > <?= ($_GET['id']) ? $lang['edit'] : $lang['add'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <form id="data-form">
                                    <div class="row">
                                        <div class="col-md-1">
                                            <label><?=$lang['sms_gateway_name']?></label>
                                        </div>
                                        <div class="col-md-5 form-group">
                                            <input type="text" class="form-control" name="name"
                                                   maxlength="45"
                                                       value="<?= $sms['name'] ?>" />
                                        </div>
                                        <div class="col-md-1">
                                            <label><?=$lang['sms_gateway_code']?></label>
                                        </div>
                                        <div class="col-md-2 form-group">
                                            <input type="text" class="form-control" name="code"
                                                   maxlength="10"
                                                    <?= ($_GET['id']) ? 'readonly' : '' ?>
                                            value="<?= $sms['code'] ?>" />
                                        </div>
                                        <label class="col-md-1">
                                            <label><?=$lang['status']?></label>
                                        </label>
                                        <div class="col-md-2 form-group">
                                            <input type="text" class="form-control" name="status"
                                                   value="<?= $sms['status'] ?>" />
                                        </div>
                                    </div>
                                    <div class="row">
                                        <label class="col-md-1">
                                            <label><?=$lang['api_url']?></label>
                                        </label>
                                        <div class="col-md-5 form-group">
                                            <input type="text" class="form-control" name="url"
                                            value="<?= $sms['url'] ?>" />
                                        </div>
                                        <label class="col-md-1">
                                            <label><?=$lang['memo']?></label>
                                        </label>
                                        <div class="col-md-5 form-group">
                                            <input type="text" class="form-control" name="memo"
                                            value="<?= $sms['memo'] ?>" />
                                        </div>
                                    </div>
                                    <div class="row">
                                        <label class="col-md-1">
                                            <label>
                                                <?=$lang['sms_gateway_info']?>
                                            </label>
                                        </label>
                                        <div class="col-md-11 form-group">
                                            <textarea class="form-control" name="info"><?= $sms['info'] ?></textarea>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <label class="col-md-1">
                                            <label>
                                                <?= $lang['support_country']?>
                                            </label>
                                        </label>
                                        <div class="col-md-11 form-group">
                                            <select class="selectize" id="country_id" name="country_id[]" multiple="multiple">
                                                <?php
                                                foreach ($countries['data'] as $row) {
                                                    ?>
                                                    <option value="<?= $row['id'] ?>" <?= in_array($row['id'], $_GET['country_id']) ? 'selected' : '' ?>>
                                                        <?= $row['name'] ?>
                                                    </option>
                                                <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12 col-xs-6">
                                            <div class="text-right mrg-top-5">
                                                <input type="hidden" name="action" value="save_sms_gateway">
                                                <input type="hidden" name="id" value="<?= $sms['id'] ?>">
                                                <input type="button" class="btn btn-default" value="<?=$lang['btn_back']?>"
                                                    onclick="history.back();" />
                                                <button type="submit" class="btn btn-primary"
                                                    id="save"><?=$lang['btn_update']?></button>
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
$(document).ready(function() {

    $('.selectize').selectize({
        plugins: ['remove_button'],
        create: false,
        dropdownParent: 'body'
    });

    //儲存資料
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
                Swal.fire(
                    '<?= $lang['sms_gateway_list'] ?> <?= ($_GET['id']) ? $lang['edit'] : $lang['add'] ?>',
                    '<?= $lang['success'] ?>',
                    'success'
                ).then(() => {
                    history.back();
                });
            } else {
                Swal.fire(
                    '<?= $lang['sms_gateway_list'] ?> <?= ($_GET['id']) ? $lang['edit'] : $lang['add'] ?>',
                    '<?= $lang['failure'] ?>' + (data.code == '2002') ? data.data : data.message,
                    'error'
                ).then(() => {
                    history.back();
                });
            }
        }, 'json');
    });
});
</script>