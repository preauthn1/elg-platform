<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['agent/advance_setting_defines'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//新增頁，部分設置要從當前代理帶預設值
$post_data = array();
$api_response = curlPost('api/agent/setting-defines', $post_data);

$code = $api_response["code"];

if ($code != 1000) {
    echo display_error_msg_html($api_response["message"]);
    exit;
}

$groups = $api_response["data"]["groups"];
$queryAgent = $api_response["data"];

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
                <h4>
                    <?= $lang['menu_system_management'] ?> >
                    <?= $lang['menu_agent_setting_define'] ?>
                </h4>
            </div>


            <div class="card">
                <div class="card-block">

                    <div>
                        <button type="button" class="btn btn-inverse btn-success" onclick="showCreateModal()"
                            style="float:none">
                            <?= $lang['add'] ?> <?= $lang["main_categoty"] ?>
                        </button>
                    </div>



                    <div class="row">

                        <div class="col-md-12">


                            <div class="">


                                <?php
                       
                        foreach ($groups as $key => $each_group) {
                            $groupID = $each_group["setting_category_id"]; ?>

                                <div id="<?= $groupID ?>" class="">


                                    <div class="group-row">

                                        <div style="width:1100px;">

                                            <span
                                                class="group-id <?= $each_group["is_visible"] == 0 ? "hideItem" : "" ?> ">
                                                <?= $each_group["setting_category_id"] ?>
                                            </span>

                                            <div class="linkText  <?= $each_group["is_visible"] == 0 ? "hideItem" : "" ?> "
                                                onclick="editText('<?= $each_group["setting_category_language_text_code"]  ?>')">
                                                <?= $each_group["setting_category_name"] ?>
                                            </div>


                                            <?php if ($each_group["is_visible"] == 0) { ?>
                                            <span class="hideMemo">此設置已隱藏</span>
                                            <?php } ?>

                                            <button type="button" class="btn btn-inverse btn-primary"
                                                onclick="copySQLCommand('<?= $each_group["setting_category_id"] ?>')">
                                                複製SQL語法
                                            </button>

                                            <button type="button" class="btn btn-inverse btn-light"
                                                onclick="showCreateSettingModal('<?= $groupID ?>' , '<?= $each_group["setting_category_name"] ?>' , '<?= $each_group["setting_category_language_text_code"] ?>' )">
                                                <?= $lang['add'] ?>
                                            </button>


                                        </div>

                                    </div>



                                    <div class="setting-panel">


                                        <?php  foreach ($each_group["settings"] as $key => $each_setting) { ?>



                                        <div
                                            class="setting-item-row <?= $each_setting["is_visible"] == 0 ? "hideItem" : "" ?> ">

                                            <div class="each-section"> <?= $each_setting["setting_id"] ?> </div>

                                            <div class="each-section-big linkText"
                                                onclick="editText('<?= $each_setting["language_text_code"]  ?>')">
                                                <?= $each_setting["text"] ?>
                                            </div>

                                            <div class="each-section-l">
                                                <?= $lang["agent_setting_define_type_".$each_setting["type"]] ?></div>

                                            <div class="each-section" title="<?= $lang["data_type"] ?>">
                                                <?= $each_setting["data_type"] ?> </div>

                                            <div class="each-section" title="<?= $lang["default_value"] ?>">
                                                <?= $each_setting["default_value"] ?>
                                            </div>


                                            <div class="each-section" title="<?= $lang["default_value"] ?>">
                                                <button type="button" class="btn btn-inverse btn-primary"
                                                    onclick="copySQLCommand('<?= $each_setting["setting_id"] ?>')">
                                                    複製SQL語法
                                                </button>
                                            </div>

                                            <?php if($each_setting["type"] == "3" && $each_setting["data_type"] != "language_text")  {?>

                                            <button type="button" class="btn btn-inverse btn-success"
                                                onclick="autoAssignDefaultValue('<?= $each_setting["setting_id"] ?>')">
                                                未設置組別塞預設值
                                            </button>

                                            <?php } ?>

                                        </div>




                                        <?php } ?>
                                    </div>


                                    </form>
                                </div>


                                <?php
                        }   ?>


                            </div>

                        </div>



                    </div>
                </div>
            </div>


        </div>
    </div>




    <div>


        <!--編輯多語系 -->
        <div id="editModal" class="modal fade" tabindex="-1" role="dialog">

            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content" style="width:850px;">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <b>
                                <?=$lang["edit"] ?> <?=$lang["multi_language_text"] ?>
                            </b>
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div id="modal-content" class="modal-body" style="padding:10px 35px;">


                    </div>


                </div>
            </div>
        </div>



        <!--新增主分類 -->
        <div id="createModal" class="modal fade" tabindex="-1" role="dialog">

            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content" style="width:650px;">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <b>
                                <?=$lang["add"] ?> <?= $lang["main_categoty"] ?>
                            </b>
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body" style="padding:10px 35px;">

                        <form id="create-form">

                            <input type="hidden" name="type" value="1">
                            <input type="hidden" name="parent" value="0">
                            <input type="hidden" name="default_value" value="">
                            <input type="hidden" name="data_type" value="">
                            <input type="hidden" name="action" value="create_agent_setting_define">


                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang['id'] ?>
                                </label>

                                <div class="col-md-8">
                                    <input type="number" class="form-control" name="setting_id" value="" max="99999999"
                                        placeholder="50000" />
                                </div>
                            </div>



                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang['language_text_code'] ?>
                                </label>

                                <div class="col-md-8">
                                    <input type="text" class="form-control" name="language_text_code" value=""
                                        maxlength="100" placeholder="agent_setting_code" />
                                </div>
                            </div>


                            <div class="form-group row">

                                <label class="col-md-3 control-label">
                                    <?= $lang['memo'] ?>
                                </label>

                                <div class="col-md-8">
                                    <input type="text" class="form-control" name="memo" value="" />
                                </div>

                            </div>

                        </form>


                    </div>


                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" onclick="saveCreate()">
                            <?=$lang["add"] ?>
                        </button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    </div>

                </div>
            </div>
        </div>



        <!--新增設定項目 -->
        <div id="createSettingModal" class="modal fade" tabindex="-1" role="dialog">

            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content" style="width:650px;">
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

                    <div class="modal-body" style="padding:10px 35px;">

                        <form id="create-setting-item-form">

                            <input type="hidden" id="targetParent" name="parent" value="0">
                            <input type="hidden" name="action" value="create_agent_setting_define">

                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang['main_categoty'] ?>
                                </label>

                                <div class="col-md-8">
                                    <input type="text" id="sample_parent" class="form-control" disabled="disabled" />
                                </div>
                            </div>



                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang['id'] ?>
                                </label>

                                <div class="col-md-8">
                                    <input type="number" class="form-control" name="setting_id" value="" max="99999999"
                                        placeholder="50000" />
                                </div>
                            </div>



                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang['language_text_code'] ?>
                                </label>

                                <div class="col-md-8">
                                    <input type="text" class="form-control" name="language_text_code" value=""
                                        placeholder="agent_setting_code" maxlength="100" />

                                    <div style="padding: 5px 15px; color: #a97549;">
                                        範例: <span id="sampleText"></span>
                                    </div>
                                </div>
                            </div>


                            <div class="form-group row">

                                <label class="col-md-3 control-label">
                                    <?= $lang['memo'] ?>
                                </label>

                                <div class="col-md-8">
                                    <input type="text" class="form-control" name="memo" value="" />
                                </div>

                            </div>



                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang['type'] ?>
                                </label>

                                <div class="col-md-8">

                                    <select id="type" name="type" class="selectized_style">

                                        <option value="" selected><?= $lang["please_choose"] ?></option>
                                        <option value="2"> <?= $lang["agent_setting_define_type_2"] ?> </option>
                                        <option value="3"> <?= $lang["agent_setting_define_type_3"] ?> </option>

                                    </select>

                                </div>
                            </div>


                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang["data_type"] ?>
                                </label>

                                <div class="col-md-8">

                                    <select id="data_type" name="data_type" class="selectized_style">

                                        <option value="" selected><?= $lang["please_choose"] ?></option>
                                        <option value="string">字串</option>
                                        <option value="boolean">布林值開關</option>
                                        <option value="datetime">時間日期</option>
                                        <option value="language_text">多語系文案</option>

                                    </select>

                                </div>
                            </div>



                            <div class="form-group row">
                                <label class="col-md-3 control-label">
                                    <?= $lang["default_value"] ?>
                                </label>

                                <div class="col-md-8">

                                    <input type="text" class="form-control" name="default_value" value="" />

                                </div>
                            </div>




                        </form>


                    </div>


                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" onclick="saveCreateSettingItem()">
                            <?=$lang["add"] ?>
                        </button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
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


});



function showCreateModal() {


    $("[name=memo]").val('');
    $("[name=setting_id]").val('');
    $("[name=language_text_code]").val('');

    $("#createModal").modal("show");
}


function showCreateSettingModal(parentID, parentName, parent_text_code) {

    $("[name=memo]").val('');
    $("[name=setting_id]").val('');
    $("[name=language_text_code]").val('');
    $("[name=default_value]").val('');
    $("#sampleText").text(parent_text_code + '_setting_001');


    $("#sample_parent").val(parentName + "(" + parentID.toString() + ")");
    $("#createSettingModal").modal("show");


    $("#targetParent").val(parentID);
}


function saveCreate() {

    $.blockUI();

    $.post("<?= BASE_URL ?>ajax.php", $("#create-form").serialize(), function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            successAlert();
            location.reload();

        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');
}



function copySQLCommand(setting_id) {

    $.blockUI();
    let formPost = "action=copy_setting_insert_sql&setting_id=" + setting_id;

    $.post("<?= BASE_URL ?>ajax.php", formPost, function(data) {
        $.unblockUI();

        if (data.code == 1000) {

            let sql = "";

            data.data.sql_list.forEach(item => {

                sql = sql + item + " \n";
            })

            clipboardHelper.copyText(sql);
            successCopyAlert();

        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');

}


function autoAssignDefaultValue(setting_id) {

    $.blockUI();
    let formPost = "action=auto_assign_customized_default_value&setting_id=" + setting_id;

    $.post("<?= BASE_URL ?>ajax.php", formPost, function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            alert(data.data);
        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');

}


function saveCreateSettingItem() {

    $.blockUI();

    $.post("<?= BASE_URL ?>ajax.php", $("#create-setting-item-form").serialize(), function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            successAlert();
            location.reload();

        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');
}



function editText(lang_code) {
    $.blockUI();

    $("#editModal").modal("show");


    var url = 'language_text_edit_ajax.php?language_code=' + lang_code;
    $("#modal-content").html("");

    $.get(url, function(data) {
        $("#modal-content").html(data);
        $.unblockUI();
    });

}
</script>


<style>
.disabledStyle {
    opacity: .5;
}


.group_name {
    display: inline-block;
    font-size: 24px;
    font-weight: 700;
}

.group-row {
    font-size: 18px;
    position: relative;
    background: #ebf2ff;
    padding: 10px;
}

.btn-row {
    display: inline-block;
    position: absolute;
    right: 5px;
}

.setting-panel {
    padding: 5px 25px 25px 25px;
    margin-bottom: 35px;
}

.label-text {
    color: #646464;
}


.form-group {
    margin-bottom: 15px;
}

.agent_name_row {
    font-size: 16px;
    font-weight: 100;
}

.agent_name_row b {
    font-weight: 700;
}


.groupCard {

    padding: 25px;
    border-radius: 10px;
    border: solid 1px #EEE;
    margin-bottom: 35px;
    background: #FFF;
}

.disabledEdit .radio-label {
    opacity: .5;
}

.group-id {
    width: 75px;
    display: inline-block;
    font-weight: 700;
}


.each-section {
    width: 125px;
    display: inline-block;
}

.each-section-m {
    width: 150px;
    display: inline-block;
}

.each-section-big,
.each-section-l {
    width: 200px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    display: inline-block;
    vertical-align: bottom;
}


.setting-item-row {
    padding: 10px;
    border-bottom: solid 1px #EEE;
}


.group-row button,
.setting-panel button {
    padding: 5px 10px;
    margin-bottom
}

.setting-panel button {
    margin: 0px !important;
    float: none;
    vertical-align: bottom;
}


.selectize-dropdown {
    z-index: 99999 !important;
}


.hideItem {
    opacity: .2;
}


.hideMemo {
    font-size: 12px;
    margin-left: 10px;
    background: #CCC;
    padding: 5px 10px;
    border-radius: 15px;
}

/* 
.form-group input {
    width: 450px;
} */
</style>