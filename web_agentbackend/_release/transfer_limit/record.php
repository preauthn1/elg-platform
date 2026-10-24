<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

if (!isset($check_auth['transfer_limit/record'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

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
                <h4><?= $lang['menu_agent_transfer_limit'] ?> > <?= $lang['menu_point_record'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="">
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-2"
                                               class="col-md-1 control-label"><?= $lang['group_name'] ?>
                                        </label>
                                        <div class="col-md-2">
                                            <select class="selectize" id="agent_group_id">
                                                <option value="0" selected>
                                                    <?= $lang['all'] ?>
                                                </option>
                                                <?php foreach ($agent_group as $row) { ?>
                                                    <option value="<?= $row['id'] ?>">
                                                        <?= $row['name'] ?>
                                                    </option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <label for="form-1-2"
                                               class="col-md-1 control-label"><?= $lang['type'] ?>

                                        </label>
                                        <div class="col-md-2">
                                            <select class="selectize" id="type">
                                                <option value="0" <?= ($_GET['type'] == 0) ? 'selected' : '' ?>>
                                                    <?= $lang['all'] ?>
                                                </option>
                                                <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                    <?= $lang['plus'] ?>
                                                </option>
                                                <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                    <?= $lang['reduce'] ?>
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="clear:both;"></div>
                                    <div class="form-group row">
                                        <label for="form-1-2"
                                               class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control datetime-picker" name="start_time"
                                                   id="start_time"
                                                   value="<?= isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d 00:00:00') ?>"/>
                                        </div>
                                        <label for="form-1-2" class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control datetime-picker" name="end_time"
                                                   id="end_time"
                                                   value="<?= isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d 23:59:59') ?>"/>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-1"></div>
                                        <div class="col-md-6">
                                            <!--今日/昨日/本周/本月...-->
                                            <?php
                                            require_once '../global/six_types_datepicker.php';
                                            ?>
                                        </div>
                                        <div class="col-md-3">
                                            <!-- <input type="reset" class="btn btn-default" value="Clear" /> -->
                                            <button type="button" class="btn btn-success" id="excel"
                                                    style="float:right">Excel
                                            </button>
                                            <button type="button" class="btn btn-primary"
                                                    onclick="loadTableData(0)"><?= $lang['search'] ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- 表格 遊戲記錄-->
        <div class="row" id="result">
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
        $('.datetime-picker').daterangepicker({
            singleDatePicker: true,
            timePicker: true,
            timePicker24Hour: true,
            timePickerIncrement: 1,
            locale: {
                format: 'YYYY-MM-DD HH:mm:ss'
            }
        });
        $('.selectize').selectize({
            create: false,
            dropdownParent: 'body'
        });

        //換頁
        $(document).on('click', '.paginate_button', function (e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            var page = $(this).attr('data-dt-idx');
            var is_disable = $(this).hasClass('disabled');
            if (!is_disable) {
                loadTableData(page);
            }
        });


        //下載
        $("#excel").on('click', function (e) {
            var isOK = check_required_form_input();
            if (!isOK) {
                return;
            }
            var start_time = $("[name='start_time']").val();
            var end_time = $("[name='end_time']").val();
            let username = $("#username").val() ? $("#username").val() : '';
            $.blockUI();
            window.location = 'ajax.php?action=sr13&vendor_id=' + '&username=' + username + '&start_time=' +
                start_time + '&end_time=' + end_time;
            setTimeout($.unblockUI, 5000);
        });

    });

    function loadTableData(page) {
        // if (page == 0) {
        //     let pageValue = $(this).attr('data-dt-idx');
        //     page = pageValue ? pageValue : 1;
        // }
        let agent_name = $("#agent_name").val() ? $("#agent_name").val() : '';
        let agent_group_id = $("#agent_group_id").val() ? $("#agent_group_id").val() : '';
        let type = $("#type").val() ? $("#type").val() : '';
        let start_time = $("#start_time").val() ? $("#start_time").val() : '';
        let end_time = $("#end_time").val() ? $("#end_time").val() : '';

        var queryString = "&agent_name=" + agent_name + "&agent_group_id=" + agent_group_id;
        queryString += "&type=" + type + "&start_time=" + start_time + "&end_time=" + end_time;
        var url = 'record_ajax.php?page=' + page + queryString;
        $.get(url, function (data) {
            $("#result").html(data);
            $.unblockUI();
        });
    }
</script>
<style>
    form label {
        padding-top: 10px;
    }
</style>