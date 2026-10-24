<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['order/order_list'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}
$queryCashflow = curlPost('api/agent/onlineDeposit/getCashFlow');
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
                    <?= $lang['member_lower_management'] ?> > <?= $lang['menu_order'] ?>
                </h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <h4 class="card-title"><?= $lang['search'] ?></h4>
                                    <form id="data-form" class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-2 control-label">
                                                <select class="selectize" id="type" name="type">
                                                    <option value="11">
                                                        <?= $lang['ordernumber'] ?>
                                                    </option>
                                                    <option value="12">
                                                        <?= $lang['ordernumber'] ?>(<?= $lang['deposit_vendor_name'] ?>)
                                                    </option>
                                                    <option value="21">
                                                        <?= $lang['member_account'] ?>
                                                    </option>
                                                    <option value="22">
                                                        <?= $lang['agent_account'] ?>
                                                    </option>
                                                </select>
                                            </label>
                                            <div class="col-md-2">
                                                <input id="searchName" type="text" class="form-control"
                                                    name="username" />
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label">
                                                <?= $lang['between'] ?>
                                            </label>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control datetime-picker"
                                                       name="start_time"
                                                       value="<?= date('Y-m-d 00:00', strtotime('-24hour')) ?>" />
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label">
                                                <?= $lang['to'] ?>
                                            </label>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control datetime-picker"
                                                       name="end_time"
                                                       value="<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>" />
                                            </div>
                                            <label for="form-1-1" class="col-md-2 control-label">
                                                <?= $lang['deposit_vendor_name'] ?>
                                            </label>
                                            <div class="col-md-2">
                                                <select class="selectize" id="cash_flow_id" name="cash_flow_id">
                                                    <option value=" ">
                                                        <?= ' '.$lang['all'] ?>
                                                    </option>
                                                    <?php
                                                    foreach($queryCashflow["data"]["cash_flow_list"] as $item) {
                                                        ?>
                                                        <option value="<?= $item['id'] ?>">
                                                            <?= $item['name'] ?>
                                                        </option>
                                                        <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1">
                                            </div>
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
                                            <label for="form-1-1" class="col-md-2 control-label">
                                                <?= $lang['status'] ?>
                                            </label>
                                            <div class="col-md-2">
                                                <select class="selectize" id="status" name="status">
                                                    <option value=" ">
                                                        <?= $lang['all'] ?>
                                                    </option>
                                                    <option value="0">
                                                        <?= $lang['order_create'] ?>
                                                    </option>
                                                    <option value="1">
                                                        <?= $lang['success'] ?>
                                                    </option>
                                                    <option value="2">
                                                        <?= $lang['failure'] ?>
                                                    </option>
                                                    <option value="3">
                                                        <?= $lang['deposit_success'] ?>
                                                    </option>
                                                    <option value="4">
                                                        <?= $lang['pending_manual_confirmation'] ?>
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-md-1">
                                            </div>
                                            <div class="col-md-5">
                                                <button type="button" class="btn btn-primary"
                                                        onclick="loadTableData(1)">
                                                    <?= $lang['search'] ?>
                                                </button>
                                                <input type="reset" class="btn btn-default clear"
                                                       value="<?= $lang['clear'] ?>" />
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
    <div id="previewManualDeposit" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="width:850px;">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <b>
                        </b>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="padding:35px;">
                    <div id="manual_modal_content">
                    </div>
                </div>
                <div class="modal-footer">
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
$(document).ready(function() {
    $.blockUI();

    //變更日期格式
    $('.datetime-picker').daterangepicker({
        singleDatePicker: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerIncrement: 1,
        locale: {
            format: 'YYYY-MM-DD HH:mm'
        }
    });
    loadTableData(1);
    dataTableHelper.initialTableSearchFormClearEvent();
    dataTableHelper.initialPagingEvent(loadTableData);

    $('.selectize').selectize({
        create: false,
        dropdownParent: 'body'
    });

    //清除查詢資料
    $(".clear").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        window.location.reload();
    });
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

function loadTableData(page) {
    $.blockUI();
    if (page == 0) {
        let pageValue = $(this).attr('data-dt-idx');;
        page = pageValue ? pageValue : 1;
    }

    var queryString = $("#data-form").serialize();
    var url = 'order_list_ajax.php?per_page=20&page=' + page + "&" + queryString + generateSearchTextByType();

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
}

function previewPopup(id, start_time, end_time) {
    $.blockUI();
    $("#manual_modal_content").html('');
    $("#previewManualDeposit").modal("show");

    var url = 'order_manual_deposit.php?id=' + id + '&start_time=' + start_time + '&end_time=' + end_time;
    $.get(url, function(data) {
        $("#manual_modal_content").html(data);
        $.unblockUI();
    });
}

function exportExcel() {
    $.blockUI();
    var queryString = $("#data-form").serialize();
    var url = "<?= BASE_URL ?>ajax_excel.php?action=excel_orders&" + queryString + generateSearchTextByType();
    window.location = url;
    setTimeout($.unblockUI, 3000);
}

function generateSearchTextByType() {
    let type = $("[name=type]").val();
    let searchName = $("#searchName").val();

    if (type == 11) {
        return "&order_no=" + searchName;
    } else if (type == 12) {
        return "&third_party_order_no=" + searchName;
    } else if (type == 21) {
        return "&member_username=" + searchName;
    } else if (type == 22) {
        return "&agent_username=" + searchName;
    }
}

//下載
$(document).on('click', '#excel', function(e) {
    exportExcel();
});
</script>