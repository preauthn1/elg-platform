<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//檢查權限
if (!check_permission_menuCode('agent_group')) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$res_orgs = curlPost('api/organization/dropdownList');
$orgs = $res_orgs['data'];
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['agnet_management'] ?> > <?= $lang['menu_agent_group'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div>
                                <h4 class="card-title">
                                    <button type="button" class="btn btn-inverse btn-success"
                                        onclick="showCreateModal()" style="float:none">
                                        <?= $lang['add'] ?>
                                    </button>
                                </h4>
                            </div>

                            <form id="data-form" class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group row">
                                            <div class="col-md-1">
                                                <?= $lang["type"] ?>
                                            </div>
                                            <div class="col-md-3">
                                                <select id="select_type" name="agent_group_type"
                                                    class="selectized_style">
                                                    <option value=" "><?=$lang["all"] ?></option>
                                                    <option value="1"><?=$lang["agent_group_type_1"] ?></option>
                                                    <option value="2"><?=$lang["agent_group_type_2"] ?></option>
                                                    <option value="3"><?=$lang["agent_group_type_3"] ?></option>
                                                    <option value="4"><?=$lang["agent_group_type_4"] ?></option>
                                                    <option value="5"><?=$lang["agent_group_type_5"] ?></option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <?= $lang["organization"] ?>
                                            </div>
                                            <div class="col-md-3">
                                                <select name="org_id" class="selectized_style">
                                                    <option value="0" checked="checked"><?= $lang["all"] ?></option>
                                                    <?php
                                                    foreach($orgs as $org) {
                                                        ?>
                                                    <option <?= ($_GET['org_id'] == $org['id']) ? 'checked' : ''?>
                                                        value="<?= $org['id'] ?>">
                                                        <?= $org['name'] ?>
                                                    </option>
                                                    <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
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
                    <form id="create-form">
                        <input type="hidden" name="action" value="create_agent_group">
                        <div>
                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['name'] ?>
                                    </label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="name" name="name" value="" />
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
                            <div class="form-group row">
                                <label class="col-md-3 control-label"><?= $lang['type'] ?></label>
                                <div class="col-md-8">
                                    <select id="select_type" name="agent_group_type" class="selectized_style">
                                        <option value=""><?=$lang["please_choose"] ?>
                                        </option>
                                        <option value="1"><?=$lang["agent_group_type_1"] ?></option>
                                        <option value="2"><?=$lang["agent_group_type_2"] ?></option>
                                        <option value="3"><?=$lang["agent_group_type_3"] ?></option>
                                        <option value="4"><?=$lang["agent_group_type_4"] ?></option>
                                        <option value="5"><?=$lang["agent_group_type_5"] ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div>
                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang['organization'] ?><?= $lang['name'] ?>
                                </label>
                                <div class="col-md-8">
                                    <select id="select_organization" name="organization_id" class="selectized_style">
                                        <option value="" selected><?= $lang["please_choose"] ?></option>
                                        <?php foreach ($orgs as $row) { ?>
                                        <option value="<?= $row['id'] ?>">
                                            <?= $row['name'] ?>
                                        </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </div>
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

    <div id="editModal" class="modal fade" tabindex="-1" role="dialog">
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
                        <input type="hidden" name="action" value="edit_agent_group">
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
                                        <?= $lang['id'] ?>
                                    </label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="edit_type" name="type" value=""
                                            disabled="disabled" />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['name'] ?>
                                    </label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="edit_name" name="name" value=""
                                            maxlength="50" />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="form-group row">
                                    <label class="col-md-3 control-label">
                                        <?= $lang['memo'] ?>
                                    </label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="edit_memo" name="memo" value=""
                                            maxlength="50" />
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


    <div id="groupAgentsModal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="width:850px;">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <b>
                            查看此組別代理
                        </b>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="padding:35px;">


                    <div id="groupAgentsModalContent">
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


function showEditModal(id, name, memo, typeName) {
    $("#editModal").modal("show");
    $("#disabled_id").val(id);
    $("#edit_id").val(id);
    $("#edit_name").val(name);
    $("#edit_memo").val(memo);
    $("#edit_type").val(typeName);
}


function copyAndCreateNew(id) {
    if (confirm("<?= $lang["are_you_sure"] ?>")) {
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        });

        let form = "action=copy_create_new_group&target_group_id=" + id;
        $.post("<?= BASE_URL ?>ajax.php", form, function(data) {
            $.unblockUI();
            if (data.code == 1000) {
                successAlert();
                loadTableData(1);
            } else {
                alert(data.message + "." + data.data);
            }
        }, 'json');
    }
}

function saveCreate() {
    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    });

    $.post("<?= BASE_URL ?>ajax.php", $("#create-form").serialize(), function(data) {
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
        let pageValue = $(this).attr('data-dt-idx');;
        page = pageValue ? pageValue : 1;
    }

    var queryString = $("#data-form").serialize();
    var url = 'group_list_ajax.php?per_page=20&page=' + page + "&" + queryString

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
}


function showGroupAgents(type, groupId) {

    let columnName = '';

    switch (type) {
        case '1':
            columnName = "customized_setting_group_id";
            break;
        case '2':
            columnName = "cash_flow_group_id";
            break;
        case '3':
            columnName = "sms_gateway_group_id";
            break;
        case '4':
            columnName = "chat_group_id";
            break;
        case '5':
            columnName = "vendor_customize_group_id";
            break;
        default:
            alert('not define code.');
    }

    var url = 'group_filterBy_groupID.php?' + columnName + '=' + groupId;

    $("#groupAgentsModalContent").html('');
    $("#groupAgentsModal").modal("show");
    $.blockUI();
    $.get(url, function(data) {
        $("#groupAgentsModalContent").html(data);
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