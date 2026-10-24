<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['sms/info_edit'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// 取詳細資料
if ($_GET['id']) {
    $filter = array();
    $filter['id'] = $_GET['id'];
    $result = curlPost('api/sms/info/show', $filter);
    if ($result['code'] == 1000) {
        $infos = $result['data'];
        $_GET['country_id'] = explode(',', $infos['country_id']);

        // get all Country
        $filter = array();
        $filter['id'] = $infos['gateway_id'];
        $countries = curlPost('api/country/mobile_list', $filter);
    }
} else {

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
                <h4><?= $lang['sms_management']?> > <?= $lang['sms_info_list']?> > <?= ($_GET['id']) ? $lang['edit'] : $lang['add'] ?></h4>
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
                                                        <label><?=$lang['sms_gateway_name']?></label>
                                                    </div>
                                                    <div class="col-md-3 form-group">
                                                        <input type="text" class="form-control" name="name" readonly
                                                               value="<?= $infos['gateway_name'] ?>" />
                                                    </div>
                                                    <div class="col-md-1">
                                                        <label><?=$lang['sort']?></label>
                                                    </div>
                                                    <div class="col-md-3 form-group">
                                                        <input type="text" class="form-control" name="sort"
                                                               value="<?= $infos['sort'] ?>" />
                                                    </div>
                                                    <div class="col-md-1">
                                                        <label><?=$lang['status']?></label>
                                                    </div>
                                                    <div class="col-md-3 form-group">
                                                        <div class="radio radio-inline">
                                                            <input type="radio" name="status" value="1" id="rad1"
                                                                <?= ($infos['status'] == 1) ? 'checked' : '' ?>>
                                                            <label for="rad1"><?= $lang['enable'] ?></label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input type="radio" name="status" value="0" id="rad2"
                                                                <?= ($infos['status'] == '0' || !isset($infos['status'])) ? 'checked' : '' ?>>
                                                            <label for="rad2"><?= $lang['disable'] ?></label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-1">
                                                        <label><?= $lang['sms_gateway_info'] ?></label>
                                                    </div>
                                                    <div class="col-md-11 form-group">
                                                        <?php
                                                        $gateway_info = json_decode($infos['gateway_info'], true);
                                                        $info = json_decode($infos['info'], true);
                                                        foreach ($gateway_info as $key => $param) {
                                                            ?>
                                                            <label class="col-md-2">
                                                                <?= $key ?>
                                                            </label>
                                                            <?php
                                                            if (isset($param['type'])) {
                                                                ?>
                                                                <div class="col-md-11 form-group">
                                                                    <input type="<?= $param['type'] ?>" class="form-control"
                                                                           name="<?= $key ?>" value="<?= $info[$key] ?>" />
                                                                </div>
                                                                <?php
                                                            }
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-1">
                                                        <label><?= $lang['support_country'] ?></label>
                                                    </div>
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
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                        <input type="hidden" name="action" value="save_sms_gateway_info">
                                                        <input type="hidden" name="id" value="<?= $infos['id'] ?>">
                                                        <input type="hidden" name="gateway_id" value="<?= $infos['gateway_id'] ?>">
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
                    '<?= $lang['sms_info_list'] ?> <?= ($_GET['id']) ? $lang['edit'] : $lang['add'] ?>',
                    '<?= $lang['success'] ?>',
                    'success'
                ).then(() => {
                    history.back();
                });
            } else {
                Swal.fire(
                    '<?= $lang['sms_info_list'] ?> <?= ($_GET['id']) ? $lang['edit'] : $lang['add'] ?>',
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