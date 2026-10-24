<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
// if (!isset($check_auth['agent/edit']) && $_GET['id'] != $_SESSION['id']) {
//     echo $lang['nopermission'];
//     exit;
// }

//新增頁，部分設置要從當前代理帶預設值
$post_data = array();
$post_data['agent_id'] = isset($_GET['agent_id']) ? $_GET['agent_id'] : $_SESSION['id'] ;
$api_response = curlPost('api/agent/personal-settings', $post_data);

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
                    <?= $lang['agnet_management'] ?> >
                    <?= $lang['menu_agent_advance_setting'] ?>
                </h4>
            </div>


            <div>

                <div></div>

                <div class="agent_name_row">

                    <input type="hidden" id="agent_id" name="agent_id" value="<?= $queryAgent["agent_id"] ?>">

                    <span>
                        <b>
                            <?= $lang["agent"] ?>
                        </b>
                    </span>：

                    <span>
                        <?= $queryAgent["agent_name"]?>
                    </span>

                    <span>
                        (<?= $queryAgent["agent_id"]?>)
                    </span>

                </div>

                <div>
                    <hr>
                </div>
            </div>


            <div class="row">

                <div class="col-md-6">


                    <div class="">


                        <?php foreach ($groups as $key => $each_group) {
        $groupID = $each_group["setting_category_id"]; ?>

                        <div id="<?= $groupID ?>" class="groupCard disabledEdit">


                            <div class="group-row ">

                                <div class="group_name">
                                    <?= $each_group["setting_category_name"] ?>
                                </div>

                                <div class="btn-row">

                                    <button class="btn btn-info js_SwitchToEdit"
                                        onclick="switchEditOrNotEdit('<?= $groupID ?>' , 1)">
                                        <?= $lang["edit"]?>
                                    </button>
                                    <button class="btn btn-success js_Save" style="display:none"
                                        onclick="save('<?= $groupID ?>')">

                                        <?= $lang["btn_update"]?>
                                    </button>
                                    <button class="btn js_Cancel" style="display:none"
                                        onclick="switchEditOrNotEdit('<?= $groupID ?>' , 0)">

                                        <?= $lang["cancel"]?>
                                    </button>

                                </div>
                            </div>


                            <form>

                                <div class="setting-panel">
                                    <?php  foreach ($each_group["settings"] as $key => $each_setting) {
            $formName = "form_".$each_setting["setting_id"];
            $radio_1 = $formName."1";
            $radio_0 = $formName."0";
            $isBoolean = $each_setting["data_type"] == "boolean";
            $isDateFormat = $each_setting["data_type"] == "datetime";
            $isEnableFlag = $each_setting["value"] == 1; ?>

                                    <div class="row">

                                        <div class="col-md-8">
                                            <div class="label-text">
                                                <?= $each_setting["text"] ?>
                                            </div>

                                            <div class="form-group">

                                                <input type="hidden" class="js_setting_id"
                                                    value="<?= $each_setting["setting_id"] ?>">

                                                <!-- 如果是時間類型的設置 -->
                                                <?php  if ($isDateFormat) { ?>

                                                <input type="text" name="<?=$formName ?>" disabled="disabled"
                                                    class="form-control datetime-picker"
                                                    value="<?= $each_setting["value"] ?>">

                                                <!-- 如果是boolean類型的設置 -->
                                                <?php } elseif ($isBoolean) {  ?>

                                                <div class="radio radio-inline">
                                                    <input type="radio" name="<?=$formName ?>" value="1"
                                                        id="<?= $radio_1 ?>" disabled="disabled"
                                                        <?= $isEnableFlag ? "checked" : "" ?>>
                                                    <label for="<?= $radio_1 ?>" class="radio-label">
                                                        <?= $lang["enable"]?>
                                                    </label>
                                                </div>

                                                <div class="radio radio-inline">
                                                    <input type="radio" name="<?=$formName ?>" value="0"
                                                        id="<?= $radio_0 ?>" disabled="disabled"
                                                        <?= $isEnableFlag ? "" : "checked" ?>>
                                                    <label for="<?= $radio_0 ?>" class="radio-label">
                                                        <?= $lang["disable"]?>
                                                    </label>
                                                </div>

                                                <!-- 其他類 最普通的文字設置 -->
                                                <?php } else { ?>

                                                <input type="text" name="<?=$formName ?>" disabled="disabled"
                                                    class="form-control" value="<?= $each_setting["value"] ?>">

                                                <?php } ?>

                                            </div>
                                        </div>

                                        <div class="col-md-4">

                                        </div>

                                    </div>


                                    <?php
        } ?>
                                </div>


                            </form>
                        </div>


                        <?php
    }   ?>


                    </div>

                </div>


                <div class="col-md-6">
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


});



function save(parent_elementID, isEditMode) {

    let settingIDs = "";

    $("#" + parent_elementID + " .js_setting_id").each(function() {
        settingIDs += $(this).val() + ",";
    });

    let myAgentID = $("#agent_id").val();
    settingIDs = settingIDs.substr(0, (settingIDs.length - 1));

    var form_post = $("#" + parent_elementID + " form").serialize() + "&action=update_advance_setting&setting_ids=" +
        settingIDs + "&agent_id=" + myAgentID;

    $.ajax({
        cache: false,
        dataType: 'json',
        data: form_post,
        url: '/ajax.php',
        type: 'POST',
        success: function(data) {

            if (data == null || data.code != 1000) {
                alert(data.message);
            } else {
                successAlert();
            }

            switchEditOrNotEdit(parent_elementID, 0);

        },
        error: function(err) {

            alert('error');

        }
    });




}



function switchEditOrNotEdit(parent_elementID, isEditMode) {

    $("#" + parent_elementID).removeClass("disabledEdit");
    $("#" + parent_elementID).find(".js_SwitchToEdit").hide();
    $("#" + parent_elementID).find(".js_Cancel").hide();
    $("#" + parent_elementID).find(".js_Save").hide();

    if (isEditMode == 1) {
        $("#" + parent_elementID).find(".js_Cancel").show();
        $("#" + parent_elementID).find(".js_Save").show();
        $("#" + parent_elementID).find("input").removeAttr("disabled");

    } else {
        $("#" + parent_elementID).addClass("disabledEdit");
        $("#" + parent_elementID).find(".js_SwitchToEdit").show();
        $("#" + parent_elementID).find("input").attr("disabled", "disabled");
    }


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
    position: relative;
}

.btn-row {
    display: inline-block;
    position: absolute;
    right: 5px;
}

.setting-panel {
    padding: 25px;
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

/* 
.form-group input {
    width: 450px;
} */
</style>