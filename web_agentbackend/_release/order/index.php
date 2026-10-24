<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['order/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
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
                <h4><?= $lang['menu_auto_deposit'] ?> > <?= $lang['menu_order_review'] ?></h4>
            </div>
            <!-- <a href="edit.php" class="btn btn-inverse btn-success"><?= $lang['add_agent'] ?></a> -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <h4 class="card-title"><?= $lang['search'] ?></h4>
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-2 control-label">
                                                <select class="selectize" name="type">
                                                    <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['ordernumber'] ?></option>
                                                    <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['member_account'] ?>
                                                    <option value="3" <?= ($_GET['type'] == 3) ? 'selected' : '' ?>>
                                                        <?= $lang['account_no'] ?>
                                                </select>
                                            </label>
                                            <div class="col-md-4">
                                                <input type="text" class="form-control" name="username"
                                                       value='<?= $_GET['username'] ?>'/>
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label">
                                                <?= $lang['between'] ?>
                                            </label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control datetime-picker"
                                                       name="start_time"
                                                       value="<?= $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('-16hour')) ?>"/>
                                            </div>
                                            <label for="form-1-2"
                                                   class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control datetime-picker" name="end_time"
                                                       value="<?= $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+8hour')) ?>"/>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-1"
                                                   class="col-md-2 control-label"><?= $lang['status'] ?></label>
                                            <div class="col-md-2">
                                                <select id="selectize-dropdown" name="status">
                                                    <option value="-1"><?= $lang['all'] ?></option>
                                                    <option value="0" <?= ($_GET['status'] == '0') ? 'selected' : '' ?>>
                                                        <?= $lang['order_create'] ?></option>
                                                    <option value="1" <?= ($_GET['status'] == '1') ? 'selected' : '' ?>>
                                                        <?= $lang['pending_review'] ?></option>
                                                    <option value="2" <?= ($_GET['status'] == '2') ? 'selected' : '' ?>>
                                                        <?= $lang['rejected'] ?></option>
                                                    <option value="3" <?= ($_GET['status'] == '3') ? 'selected' : '' ?>>
                                                        <?= $lang['pass'] ?></option>
                                                </select>
                                            </div>
                                            <div class="col-md-2"></div>
                                            <div class="col-md-6">
                                                <a id="today" style="float:left"
                                                   class="btn btn-outline-info btn-sm"><?= $lang['today'] ?></a>
                                                <a id="yesterday" style="float:left"
                                                   class="btn btn-outline-info btn-sm"><?= $lang['yesterday'] ?></a>
                                                <a id="this_week" style="float:left"
                                                   class="btn btn-outline-info btn-sm"><?= $lang['this_week'] ?></a>
                                                <a id="last_week" style="float:left"
                                                   class="btn btn-outline-info btn-sm"><?= $lang['last_week'] ?></a>
                                                <a id="this_month" style="float:left"
                                                   class="btn btn-outline-info btn-sm"><?= $lang['this_month'] ?></a>
                                                <a id="last_month" style="float:left"
                                                   class="btn btn-outline-info btn-sm"><?= $lang['last_month'] ?></a>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label"></label>
                                            <div class="col-md-10">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <input type="reset" class="btn btn-default clear"
                                                       value="<?= $lang['clear'] ?>"/>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" id="result">
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
                format: 'YYYY-MM-DD HH:mm'
            }
        });

        $('#today').click(function(e) {
            $("[name='start_time']").val("<?= date('Y-m-d 00:00', strtotime('+8hour')) ?>");
            $("[name='end_time']").val("<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>");
        });

        $('#yesterday').click(function(e) {
            $("[name='start_time']").val("<?= date('Y-m-d 00:00', strtotime('-1day +8hour')) ?>");
            $("[name='end_time']").val("<?= date('Y-m-d 23:59', strtotime('-1day +8hour')) ?>");
        });

        $('#this_week').click(function(e) {
            $("[name='start_time']").val(
                "<?= date('Y-m-d 00:00', strtotime(- (date('N') - 1) . 'day +8hour')) ?>"
            );
            $("[name='end_time']").val(
                "<?= date('Y-m-d 23:59', strtotime((7 - date('N')) . 'day +8hour')) ?>"
            );
        });
        $('#last_week').click(function(e) {
            $("[name='start_time']").val(
                "<?= date('Y-m-d 00:00', strtotime(- (date('N') + 6) . 'day +8hour')) ?>"
            );
            $("[name='end_time']").val(
                "<?= date('Y-m-d 23:59', strtotime(- (date('N')) . 'day +8hour')) ?>"
            );
        });
        $('#this_month').click(function(e) {
            $("[name='start_time']").val("<?= date('Y-m-01 00:00', strtotime(' +8hour')) ?>");
            $("[name='end_time']").val("<?= date('Y-m-t 23:59', strtotime(' +8hour')) ?>");
        });
        $('#last_month').click(function(e) {
            $("[name='start_time']").val("<?= date('Y-m-01 00:00', strtotime('-1month +8hour')) ?>");
            $("[name='end_time']").val("<?= date('Y-m-t 23:59', strtotime('-1month +8hour')) ?>");
        });

        $.blockUI();
        $.get('index_ajax.php' + $(location).attr('search'), function (data) {
            $("#result").html(data);
            $.unblockUI();
        });

        $('.selectize').selectize({
            create: false,
            // sortField: {
            //     field: 'text',
            //     direction: 'asc'
            // },
            dropdownParent: 'body'
        });

        //清除查詢資料
        $(".clear").click(function (e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            window.location = 'index.php';
        });

    });

    function clickImage() {
        var image = $('#image_url').val();
        if (image !== ' ') {
            window.open(image);
        }
    }

    //下載
    $(document).on('click', '#excel', function (e) {
        var type = $("[name='type']").val();
        var username = $("[name='username']").val();
        var status = $("[name='status']").val();
        var start_time = $("[name='start_time']").val();
        var end_time = $("[name='end_time']").val();
        window.location = 'ajax.php?action=order&type=' + type + '&username=' + username + '&status=' + status
            + '&start_time=' + start_time + '&end_time=' + end_time;
    });
</script>