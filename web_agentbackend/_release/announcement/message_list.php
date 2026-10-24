<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['announcement/message_list'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$res_agents = curlPost('api/agent/dropdownList');
$agents = $res_agents['data'];


$res_orgs = curlPost('api/organization/dropdownList');
$orgs = $res_orgs['data'];



$is_create_permission_exist = check_permission_menuCode('announcement_message_create');



//確認權限
// if (!isset($check_auth['agent/index'])) {
//     echo '您無此權限';
//     exit;
// }


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
                <h4><?= $lang['menu_system_management'] ?> > <?= $lang['menu_announcement_message_list'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-7">

                                    <h4 class="card-title"><?= $lang['search'] ?></h4>

                                    <form id="createForm" class="form-horizontal mrg-top-40 pdd-right-30">


                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-3 control-label">
                                            </label>

                                            <div class="col-md-10">

                                                <?php if($is_create_permission_exist) { ?>

                                                <button type="button" class="btn btn-primary" style="float:left"
                                                    onclick="showCreateModal()">
                                                    <?= $lang['add'] ?>
                                                </button>

                                                <?php } ?>

                                            </div>
                                        </div>



                                        <!-- 
                                        <div class=" form-group row">
                                                    <label for="form-1-2" class="col-md-3 control-label"></label>
                                                    <div class="col-md-10">
                                                        <button type="button" class="btn btn-primary"
                                                            onclick="loadTableData(0)">
                                                            <?= $lang['search'] ?>
                                                        </button>

                                                    </div>
                                            </div> -->

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


    <div id="createModal" class="modal fade" tabindex="-1" role="dialog">

        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="width:850px;">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <b>
                            <?=$lang["add"] ?>
                        </b>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body" style="padding:35px;">


                    <form id="data-form">

                        <input type="hidden" name="action" value="save_announcement_messages">

                        <div>


                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['content'] ?>
                                    </label>


                                    <div class="col-md-8">

                                        <textarea class="form-control" id="message" name="message">
                                        </textarea>

                                    </div>

                                </div>
                            </div>


                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['memo'] ?>
                                    </label>

                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="memo" name="memo" value="" />
                                    </div>

                                </div>
                            </div>



                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['label_start_time'] ?>
                                    </label>

                                    <div class="col-md-8">
                                        <input type="text" class="form-control datetime-picker" id="start_time"
                                            name="start_time" value="" />
                                    </div>

                                </div>
                            </div>



                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['label_end_time'] ?>
                                    </label>

                                    <div class="col-md-8">
                                        <input type="text" class="form-control datetime-picker" id="end_time"
                                            name="end_time" value="" />
                                    </div>

                                </div>
                            </div>




                            <div class="form-group row">
                                <label class="col-md-3 control-label"><?= $lang['type'] ?></label>

                                <div class="col-md-8">

                                    <select id="select_type" name="type">

                                        <option value=""><?=$lang["please_choose"] ?>
                                        </option>

                                        <option value="99"><?=$lang["announcement_messages_participants_type_99"] ?>
                                        </option>

                                        <option value="2"><?=$lang["announcement_messages_participants_type_2"] ?>
                                        </option>

                                    </select>

                                </div>
                            </div>

                        </div>


                        <div>

                            <div class="form-group row">
                                <label class="col-md-3 control-label"><?= $lang['sendMessage_target'] ?></label>
                                <div class="col-md-8">


                                    <div id="select_sample">

                                        <select id="xxxxxx" name="xxxxx" class="selectized_style">

                                            <option value="-" selected>
                                                -
                                            </option>

                                        </select>

                                    </div>


                                    <div id="select_organization_panel" style="display:none">

                                        <select id="select_organization" name="organization_id"
                                            class="selectized_style">

                                            <option value="" selected><?= $lang["please_choose"] ?></option>

                                            <?php foreach ($orgs as $row) { ?>

                                            <option value="<?= $row['id'] ?>">
                                                <?= $row['name'] ?>
                                            </option>

                                            <?php } ?>

                                        </select>

                                    </div>

                                    <div id="select_agent_panel" style="display:none">

                                        <select id="select_agent" name="hierarchy_agent_id" class="selectized_style">

                                            <option value="" selected><?= $lang["please_choose"] ?></option>
                                            <?php foreach ($agents as $row) { ?>

                                            <option value=" <?= $row['id'] ?>">
                                                <?= $row['username'] ?>
                                            </option>

                                            <?php } ?>

                                        </select>

                                    </div>

                                </div>
                            </div>
                        </div>




                        <!-- 
                        <div class="row">
                            <div class="col-md-5 col-xs-6">
                                <div class="text-right mrg-top-5">
                                    <input type="hidden" name="update_id" value="<?=$targetID ?>">
                                    <input type="hidden" name="action" value="save_vendor_maintenances">


                                    <button type="submit" class="btn btn-primary"
                                        id="save"><?= $lang['btn_update'] ?></button>



                                    <a href="index.php">
                                        <button type="button" class="btn"><?= $lang['btn_back'] ?></button>
                                    </a>


                                </div>
                            </div>
                        </div> -->


                    </form>


                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="saveCreate()">
                        <?=$lang["btn_update"] ?>
                    </button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
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
<div class="modal fade" id="domain_list">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="domain_content">
        </div>
    </div>
</div>
<?php if ($_SESSION['has_transaction']) { ?>
<div class="modal fade" id="transaction">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="transaction_content">
        </div>
    </div>
</div>
<?php } ?>
<script>
$(document).ready(function() {

    loadTableData(1);


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

    dataTableHelper.initialTableSearchFormClearEvent();

    dataTableHelper.initialPagingEvent(loadTableData);


    $('.selectized_style').selectize({
        create: false,
        dropdownParent: 'body'
    });

    $('#select_type').selectize({
        create: false,
        dropdownParent: 'body',
        onChange: function(value) {
            changeDropdownVisible(value);
        }
    });

});


function saveCreate() {

    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    });

    $.post("<?= BASE_URL ?>ajax.php", $("#data-form").serialize(), function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            successAlert();
            $("#createModal").modal("hide");
            loadTableData(1);

        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');


}


function changeDropdownVisible(selectValue) {

    hideAllSelectPanel();

    if (selectValue == 99) {
        $("#select_organization_panel").show();

    } else if (selectValue == 2) {
        $("#select_agent_panel").show();
    }
}


function hideAllSelectPanel() {

    $("#select_agent_panel").hide();
    $("#select_organization_panel").hide();
    $("#select_sample").hide();
}



function showCreateModal() {

    $("#createModal").modal("show");
    $("#message").val('');
    $("#memo").val('');

    $("#start_time").val('<?= date('Y-m-d 00:00', strtotime('+8hour')) ?>');
    $("#end_time").val('<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>');


    try {
        $("#select_type")[0].selectize.clear();
        $("#select_agent")[0].selectize.clear();
        $("#select_organization")[0].selectize.clear();

    } catch (error) {

    }

    hideAllSelectPanel();
    $("#select_sample").show();

}

function loadTableData(page) {
    $.blockUI();

    if (page == 0) {

        let pageValue = $(this).attr('data-dt-idx');;
        page = pageValue ? pageValue : 1;
    }

    let username = $("#username").val() ? $("#username").val() : '';
    let start_time = $("#start_time").val() ? $("#start_time").val() : '';
    let end_time = $("#end_time").val() ? $("#end_time").val() : '';

    var queryString = "&username=" + username + "&start_time=" + start_time + "&end_time=" + end_time;;
    var url = 'message_list_ajax.php?page=' + page + queryString

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });

}
</script>

<style>
.selectize-dropdown {
    z-index: 99999 !important;
}
</style>