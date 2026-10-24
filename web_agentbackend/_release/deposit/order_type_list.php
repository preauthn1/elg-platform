<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//檢查權限

// if (!check_permission_menuCode('agent_group')) {
//     echo display_error_msg_html($lang['nopermission']);
//     exit;
// }


?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['menu_system_management'] ?> > <?= $lang['menu_deposit_order_types_list'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div>
                                <h4 class="card-title">
                                    <!-- <button type="button" class="btn btn-inverse btn-success"
                                        onclick="showCreateModal()" style="float:none">
                                        <?= $lang['add'] ?>
                                    </button> -->
                                </h4>
                            </div>

                            <form id="data-form" class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group row">
                                            <div class="col-md-1">
                                                <?= $lang["name"] ?>
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" name="name" class="form-control">
                                            </div>


                                        </div>
                                        <div class="form-group row">
                                            <div class="col-md-4">
                                                <button type="reset" class="btn">
                                                    <?= $lang['clear'] ?>
                                                </button>
                                                <button type="button" class="btn btn-primary"
                                                    onclick="loadTableData(1)">
                                                    <?= $lang['search'] ?>
                                                </button>
                                            </div>
                                        </div>


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


    <div id="editModal" class="modal fade" tabindex="-1" role="dialog" style="overflow-x:auto;">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="width:850px;">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <b>
                            <?=$lang["detailinfo_edit"] ?>
                        </b>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="padding:35px;">
                    <form id="edit-form">
                        <input type="hidden" name="action" value="edit_order_types">
                        <input type="hidden" id="edit_id" name="id" value="">
                        <div>
                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['id'] ?>
                                    </label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="disabled_id" name="disabled_id"
                                            value="" disabled="disabled" />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['name'] ?>
                                    </label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="edit_name" name="name" value="" />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['code'] ?>
                                    </label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="edit_key" name="key" value=""
                                            maxlength="50" />
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['sorts'] ?>
                                    </label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="edit_sort" name="sorts" value=""
                                            maxlength="50" />
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['status'] ?>
                                    </label>
                                    <div class="col-md-8">

                                        <div class="radio radio-inline">
                                            <input type="radio" name="status" value="1" id="status_radio_1">
                                            <label for="status_radio_1"><?= $lang['enable'] ?></label>
                                        </div>
                                        <div class="radio radio-inline">
                                            <input type="radio" name="status" value="0" id="status_radio_0">
                                            <label for="status_radio_0"><?= $lang['disable'] ?></label>
                                        </div>


                                    </div>
                                </div>
                            </div>



                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['picture'] ?>
                                    </label>
                                    <div class="col-md-8">

                                        <label for="img-upload" class="pointer">
                                            <img src="" id="show_image" style="max-height:300px;max-width:300px" />
                                            <input type="hidden" name="image_path" id="edit_image_path">
                                            <span class="btn btn-default display-block no-mrg-btm">
                                                <?= $lang['choose_file'] ?>
                                            </span>
                                            <input class="d-none" type="file" name="image" id="img-upload">
                                        </label>

                                    </div>
                                </div>
                            </div>


                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="saveEdit()">
                        <?=$lang["btn_update"] ?>
                    </button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
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

    $('.selectized_style').selectize({
        create: false,
        dropdownParent: 'body'
    });



    var config_param = {
        input_file_elementID: 'img-upload',
        preivew_image_elementID: 'show_image',
        input_tempUrl_elementID: 'edit_image_path',
        uploadCallback: function(response) {


        }
    };

    imageHelper.reigsterFileUploadAutoUploadToRemoteEvent(config_param);


    loadTableData(1);

    dataTableHelper.initialTableSearchFormClearEvent();

    dataTableHelper.initialPagingEvent(loadTableData);
});

function showCreateModal() {
    $("#createModal").modal("show");
    $("#name").val('');
    $("#memo").val('');

    try {
        $("#select_type")[0].selectize.clear();
        $("#select_organization")[0].selectize.clear();
    } catch (error) {

    }

    hideAllSelectPanel();
    $("#select_sample").show();
}


function showEditModal(id, name, key, sort, status, image_path) {
    $("#editModal").modal("show");
    $("#disabled_id").val(id);
    $("#edit_id").val(id);
    $("#edit_name").val(name);
    $("#edit_key").val(key);
    $("#edit_sort").val(sort);

    if (status == 1 || status == "1") {
        $("#status_radio_1").checked = true;
        $("#status_radio_1").attr("checked", "checked");
        $("input[name=status]").val(['1']);
    } else {
        $("#status_radio_0").checked = true;
        $("#status_radio_0").attr("checked", "checked");
        $("input[name=status]").val(['0']);
    }

    $("#show_image").attr('src', image_path);
    $("#edit_image_path").val(image_path);
}


function saveEdit() {
    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    });

    $.post("<?= BASE_URL ?>ajax.php", $("#edit-form").serialize(), function(data) {
        $.unblockUI();
        if (data.code == 1000) {
            successAlert();
            $("#editModal").modal("hide");
            loadTableData(1);

        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');
}

function loadTableData(page) {
    $.blockUI();
    if (page == 0) {
        let pageValue = $(this).attr('data-dt-idx');
        page = pageValue ? pageValue : 1;
    }

    var queryString = $("#data-form").serialize();
    var url = 'order_type_list_ajax.php?per_page=20&page=' + page + "&" + queryString

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

.agent_group_type_1,
.agent_group_type_2,
.agent_group_type_3,
.agent_group_type_4 {
    font-weight: 700;
}

.agent_group_type_1 {
    color: #3f6eb4;
}

.agent_group_type_2 {
    color: #ba2121;
}

.agent_group_type_3 {
    color: #666;
}

.agent_group_type_4 {
    color: #248d1d;
}
</style>