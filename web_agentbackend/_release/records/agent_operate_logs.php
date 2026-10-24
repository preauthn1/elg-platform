<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';


//確認權限
$is_permission_exist = check_permission_menuCode('agent_operate_logs');

if (!$is_permission_exist) {
    echo display_error_msg_html($lang["nopermission"]);
    exit;
}

$res_dropdown = curlPost('api/agent/operate-log-types/dropdownList');
$types = $res_dropdown['data'];



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
                <h4><?= $lang['agnet_management'] ?> > <?= $lang['menu_agent_operate_logs'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">


                            <form id="data-form" class="form-horizontal mrg-top-40 pdd-right-30">


                                <div class="row">

                                    <div class="col-md-11">


                                        <div class="form-group row">


                                            <div class="col-md-2">
                                                <?= $lang["type"] ?>
                                            </div>

                                            <div class="col-md-4">

                                                <select id="select_type" name="type" class="selectized_style">

                                                    <option value=" ">
                                                        <?=$lang["all"] ?>
                                                    </option>

                                                    <?php foreach ($types as $value) { ?>


                                                    <option value="<?= $value["value"] ?>">
                                                        <?= $value["text"] ?>
                                                    </option>


                                                    <?php  } ?>

                                                </select>

                                            </div>

                                            <div class="col-md-6">
                                            </div>

                                        </div>

                                        <div class="form-group row">


                                            <div class="col-md-2">
                                                <?= $lang["operator"] ?>
                                                ( <?= $lang["agent"] ?>)
                                            </div>

                                            <div class="col-md-4">
                                                <input type="text" class="form-control" name="operator_username" />
                                            </div>

                                            <div class="col-md-6">
                                            </div>

                                        </div>

                                        <div class="form-group row">

                                            <div class="col-md-2">
                                                <?= $lang["update_target"] ?>
                                            </div>

                                            <div class="col-md-4">
                                                <input type="text" class="form-control" name="target_username" />
                                            </div>

                                            <div class="col-md-6">
                                            </div>
                                        </div>



                                        <div class="form-group row">

                                            <div class="col-md-2">
                                                <?= $lang["search_time"] ?>
                                            </div>

                                            <div class="col-md-2">
                                                <input type="text" class="form-control datetime-picker m-size"
                                                    name="start_time"
                                                    value="<?= date('Y-m-d 00:00', strtotime('+8hour')) ?>" />
                                            </div>

                                            <div class="col-md-1">
                                                <div style="padding: 10px;">
                                                    To
                                                </div>
                                            </div>

                                            <div class="col-md-2">
                                                <input type="text" class="form-control datetime-picker m-size"
                                                    name="end_time"
                                                    value="<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>" />
                                            </div>


                                            <div class="col-md-5" style="padding-left: 35px;">

                                                <!--今日/昨日/本周/本月...-->
                                                <?php require_once '../global/six_types_datepicker.php'; ?>

                                            </div>
                                        </div>



                                        <div class="form-group row">

                                            <div class="col-md-12">

                                                <button type="button" class="btn btn-success" onclick="exportExcel()">
                                                    Excel
                                                </button>

                                                <button type="button" class="btn btn-primary"
                                                    onclick="loadTableData(1)">
                                                    <?= $lang['search'] ?>
                                                </button>


                                                <button type="reset" class="btn">
                                                    <?= $lang['clear'] ?>
                                                </button>


                                            </div>
                                        </div>




                                    </div>


                                    <div class="col-md-2">



                                    </div>




                                </div>


                            </form>
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
$(document).ready(function() {


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


    $('.selectized_style').selectize({
        create: false,
        dropdownParent: 'body'
    });


    loadTableData(1);

    dataTableHelper.initialTableSearchFormClearEvent();

    dataTableHelper.initialPagingEvent(loadTableData);

});


function loadTableData(page) {

    $.blockUI();

    if (page == 0) {

        let pageValue = $(this).attr('data-dt-idx');;
        page = pageValue ? pageValue : 1;
    }

    var queryString = $("#data-form").serialize();
    var url = 'agent_operate_logs_ajax.php?per_page=10&page=' + page + "&" + queryString

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
}



function exportExcel() {

    $.blockUI();

    var queryString = $("#data-form").serialize();

    var url = "<?= BASE_URL ?>ajax_excel.php?action=excel_agent_operation_logs&" + queryString

    window.location = url;
    setTimeout($.unblockUI, 3000);

}
</script>

<style>
.selectize-dropdown {
    z-index: 99999 !important;
}
</style>