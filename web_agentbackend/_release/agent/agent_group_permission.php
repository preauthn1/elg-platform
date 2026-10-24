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
$post_data['agent_id'] = isset($_GET['agent_id']) ? $_GET['agent_id'] : $_SESSION['id'];
$api_response = curlPost('api/agent/agent_group_permissions', $post_data);

$code = $api_response["code"];

if ($code != 1000) {
    var_dump($api_response);
    echo display_error_msg_html($api_response["message"]);
    exit;
}

$groups = $api_response["data"]["groups"];
$has_permissions = $api_response["data"]["has_permissions"];
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
                    <?= $lang['agnet_management'] ?> > 代理組別權限
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
                        <?= $queryAgent["agent_name"] ?>
                    </span>
                    <span>
                        (<?= $queryAgent["agent_id"] ?>)
                    </span>
                </div>
                <div>
                    <hr>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="">
                        <div id="agent_group_permission" class="groupCard disabledEdit">
                            <div class="group-row ">
                                <div class="group_name">
                                    代理組別權限
                                </div>
                                <div class="btn-row">
                                    <button class="btn btn-info js_SwitchToEdit"
                                            onclick="switchEditOrNotEdit(1)">
                                        <?= $lang["edit"] ?>
                                    </button>
                                    <button class="btn btn-success js_Save" style="display:none"
                                            onclick="save()">
                                        <?= $lang["btn_update"] ?>
                                    </button>
                                    <button class="btn js_Cancel" style="display:none"
                                            onclick="switchEditOrNotEdit(0)">
                                        <?= $lang["cancel"] ?>
                                    </button>
                                </div>
                            </div>
                            <form>
                                <div class="setting-panel">
                                    <?php
                                    $type_id = -1;
                                    foreach ($groups as $row) {
                                    if ($type_id != $row['group_type']) {
                                    if ($type_id != -1) { ?>
                                </div>
                                <?php } ?>
                                <hr/>
                                <div class="row">
                                    <h4><b><?php switch ($row['group_type']) {
                                                case 1:
                                                    echo "代理個性化設置";
                                                    break;
                                                case 2:
                                                    echo "支付設置組別";
                                                    break;
                                                case 3:
                                                    echo "簡訊設置組別";
                                                    break;
                                                case 4:
                                                    echo "聊天設置";
                                                    break;
                                                case 5:
                                                    echo "遊戲個性化設置";
                                                    break;
                                                default:
                                                    echo '其他設置';
                                                    break;
                                            } ?>
                                        </b></h4>
                                </div>
                                <div class="row">
                                    <?php
                                    $type_id = $row['group_type'];
                                    }
                                    ?>
                                    <div class="col-md-6">
                                        <input type="checkbox" name="group_id_<?= $row['group_id'] ?>"
                                            <?php if (in_array($row['group_id'], $has_permissions)) { ?>
                                                checked="checked"
                                            <?php } ?> value="<?= $row['group_id'] ?>" disabled="disabled">
                                        <label><?= $row['group_id'] ?></label>
                                        <label><?= $row['group_name'] ?></label>
                                    </div>
                                <?php } ?>
                        </div>
                        </form>
                    </div>
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
    $(document).ready(function () {

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


    function save() {
        let myAgentID = $("#agent_id").val();

        var form_post = $("#agent_group_permission form").serialize() + "&action=update_agent_group_permission" +
            "&agent_id=" + myAgentID;
        $.ajax({
            cache: false,
            dataType: 'json',
            data: form_post,
            url: '/ajax.php',
            type: 'POST',
            success: function (data) {
                if (data == null || data.code != 1000) {
                    alert(data.message);
                    alert(data.message);
                } else {
                    successAlert();
                }
                switchEditOrNotEdit(0);
            },
            error: function (err) {
                alert('error');
            }
        });
    }

    function switchEditOrNotEdit(isEditMode) {

        $("#agent_group_permission").removeClass("disabledEdit");
        $("#agent_group_permission").find(".js_SwitchToEdit").hide();
        $("#agent_group_permission").find(".js_Cancel").hide();
        $("#agent_group_permission").find(".js_Save").hide();

        if (isEditMode == 1) {
            $("#agent_group_permission").find(".js_Cancel").show();
            $("#agent_group_permission").find(".js_Save").show();
            $("#agent_group_permission").find("input").removeAttr("disabled");

        } else {
            $("#agent_group_permission").addClass("disabledEdit");
            $("#agent_group_permission").find(".js_SwitchToEdit").show();
            $("#agent_group_permission").find("input").attr("disabled", "disabled");
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