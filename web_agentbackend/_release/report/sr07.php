<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['report/sr07'])) {
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
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['sr07'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <div class="col-md-2" style="padding-bottom:30px;">
                                                <?= $lang['member_account'] ?>
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
                                            </div>
                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control date-picker" name="start_date"
                                                    value="<?= $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d') ?>" />
                                            </div>
                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control date-picker" name="end_date"
                                                    value="<?= $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d') ?>" />
                                            </div>
                                            <div class="col-md-6"></div>
                                            <div class="col-md-5">
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
                                            <div class="col-md-1">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <!-- <input type="reset" class="btn btn-default" value="Clear" /> -->
                                            </div>
                                        </div>
                                    </form>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 Member-->
            <div class="row" id="record"></div>
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
    //變更日期格式
    $('.date-picker').daterangepicker({
        singleDatePicker: true,
        locale: {
            format: 'YYYY-MM-DD'
        }
    });

    $('.selectize').selectize({
        create: false,
        // sortField: {
        //     field: 'text',
        //     direction: 'asc'
        // },
        dropdownParent: 'body'
    });


    $('#today').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-d', strtotime('+8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-d', strtotime('+8hour')) ?>");
    });

    $('#yesterday').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-d', strtotime('-1day +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-d', strtotime('-1day +8hour')) ?>");
    });

    $('#this_week').click(function(e) {
        $("[name='start_date']").val(
            "<?= date('Y-m-d', strtotime(- (date('N') - 1) . 'day +8hour')) ?>"
        );
        $("[name='end_date']").val(
            "<?= date('Y-m-d', strtotime((7 - date('N')) . 'day +8hour')) ?>"
        );
    });
    $('#last_week').click(function(e) {
        $("[name='start_date']").val(
            "<?= date('Y-m-d', strtotime(- (date('N') + 6) . 'day +8hour')) ?>"
        );
        $("[name='end_date']").val(
            "<?= date('Y-m-d', strtotime(- (date('N')) . 'day +8hour')) ?>"
        );
    });
    $('#this_month').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-01', strtotime(' +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-t', strtotime(' +8hour')) ?>");
    });
    $('#last_month').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-01', strtotime('-1month +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-t', strtotime('-1month +8hour')) ?>");
    });

    //下載
    $(document).on('click', '#excel', function(e) {
        var type = $("[name='type']").val();
        var username = $("[name='username']").val();
        var start_date = $("[name='start_date']").val();
        var end_date = $("[name='end_date']").val();
        var status = $("[name='status']").val();
        window.location = 'ajax.php?action=sr07&type=' + type + '&username=' + username +
            '&start_date=' + start_date + '&end_date=' + end_date;
    });

    //換頁
    $(document).on('click', '.paginate_button', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var page = $(this).attr('data-dt-idx');
        var is_disable = $(this).hasClass('disabled');
        if (!is_disable) {
            var result = $(location).attr('search').split('&page')[0]
            if (result == '') {
                window.location = $(location).attr('href') + '?page=' + page;
            } else {
                window.location = result + '&page=' + page;
            }
        }
    });

    $.blockUI();
    $.get('sr07_ajax.php' + $(location).attr('search'), function(data) {
        $("#record").html(data);
        $.unblockUI();
    });
});
</script>