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
$post_data['group_id'] = $_GET['group_id'] ;
$api_response = curlPost('api/agent/sms/agent_group_setting', $post_data);
if ($api_response["code"] != 1000) {
    echo display_error_msg_html($api_response["message"]);
    exit;
}

$group_detail = $api_response["data"]["group_detail"];
$sms_texts = $api_response["data"]["sms_text"];
$queryGroup = $api_response["data"];

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
                    <?= $lang['agnet_management'] ?> >
                    <?= $lang['menu_agent_advance_setting'] ?>
                </h4>
            </div>
            <div>
                <div></div>
                <div class="agent_name_row">
                    <div class="row">
                        <div class="col-md-8">
                            <input type="hidden" id="group_id" name="group_id" value="<?= $queryGroup["group_id"] ?>">
                            <span>
                                <b>
                                    <?= $lang["name"] ?>
                                </b>
                            </span>：
                            <span>
                                <?= $queryGroup["group_name"]?>
                            </span>
                            <span>
                                (<?= $queryGroup["group_id"]?>)
                            </span>
                            <a href="/agent/group_list.php" style="float:right" class="linkText">
                                <?= $lang["btn_back"]?>
                            </a>
                        </div>
                    </div>
                </div>
                <div>
                    <hr>
                </div>
            </div>
            <div class="row">
                <div class="col-md-8">
                    <div>
                        <div id="detail" class="groupCard disabledEdit">
                            <div class="group-row">
                                <div class="group_name">
                                    <?= $lang['sms_group_detail_setting'] ?>
                                </div>
                                <div class="btn-row">
                                    <!-- 開關編輯/儲存按鈕 -->
                                    <button class="btn btn-info js_SwitchToEdit"
                                            onclick="switchEditOrNotEdit('detail', 1)">
                                        <?= $lang["edit"]?>
                                    </button>
                                    <button class="btn btn-success js_Save" style="display:none"
                                            onclick="save('detail')">
                                        <?= $lang["btn_update"]?>
                                    </button>
                                    <button class="btn js_Cancel" style="display:none"
                                            onclick="switchEditOrNotEdit('detail', 0)">
                                        <?= $lang["cancel"]?>
                                    </button>
                                </div>
                            </div>
                            <form>
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="label-text">
                                            <?= $sms_text["remark"] ?>
                                        </div>
                                        <div class="form-group">
                                            <div style="margin-bottom: 35px;">
                                                <?php
                                                foreach(json_decode($group_detail['setting']) as $key => $value) {
                                                    ?>
                                                    <div class="sub-category">
                                                            <span class="language-label">
                                                                <?= $lang['sms_detail.' . $key] ?>
                                                            </span>
                                                    <input type="text"
                                                           name="<?= $key ?>"
                                                           disabled="disabled"
                                                           class="form-control col-md-4"
                                                           placeholder="<?= $value ?>"
                                                           value="<?= ($group_detail['id'] == 0) ? '' : $value ?>" />
                                                </div>
                                                <?php
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8">
                    <div>
                        <div id="sms_text" class="groupCard disabledEdit">
                            <div class="group-row">
                                <div class="group_name">
                                    <?= $lang['sms_content'] ?>
                                </div>
                                <div class="btn-row">
                                    <!-- 開關編輯/儲存按鈕 -->
                                    <button class="btn btn-info js_SwitchToEdit"
                                            onclick="switchEditOrNotEdit('sms_text', 1)">
                                        <?= $lang["edit"]?>
                                    </button>
                                    <button class="btn btn-success js_Save" style="display:none"
                                            onclick="save('sms_text')">
                                        <?= $lang["btn_update"]?>
                                    </button>
                                    <button class="btn js_Cancel" style="display:none"
                                            onclick="switchEditOrNotEdit('sms_text', 0)">
                                        <?= $lang["cancel"]?>
                                    </button>
                                </div>
                            </div>
                            <form>
                                <div class="setting-panel">
                                    <?php
                                    $remark = '';
                                    foreach ($sms_texts as $key => $sms_text) {
                                        if ($remark != $sms_text["remark"]) {
                                            if ($remark != '') {
                                                ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                        </div>
                                    </div>
                                                <?php
                                            }
                                            ?>
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="label-text">
                                                <?= $lang['sms_text_type.'.$sms_text["parameter"]] ?>
                                            </div>
                                            <div class="form-group">
                                                <div style="margin-bottom: 35px;">
                                            <?php
                                            $remark = $sms_text["remark"];
                                        }
                                        ?>
                                        <div class="sub-category">
                                                <span class="language-label">
                                                    <?= $sms_text['country_name']?>
                                                </span>
                                            <input type="text"
                                                   name="sms_text[<?= $sms_text["type_id"] ?>][<?= $sms_text["country_id"] ?>]"
                                                   disabled="disabled"
                                                   class="form-control col-md-4"
                                                   value="<?= $sms_text["text"] ?>" />
                                        </div>
                                        <?php
                                    }
                                    ?>
                            </form>
                        </div>
                    </div>
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
    function queryStringToJSON(qs) {
        qs = qs || location.search.slice(1);

        var pairs = qs.split('&');
        var result = {};
        pairs.forEach(function(p) {
            var pair = p.split('=');
            var key = pair[0];
            var value = decodeURIComponent(pair[1] || '');

            if( result[key] ) {
                if( Object.prototype.toString.call( result[key] ) === '[object Array]' ) {
                    result[key].push( value );
                } else {
                    result[key] = [ result[key], value ];
                }
            } else {
                result[key] = value;
            }
        });
        return encodeURI(JSON.stringify(result));
    };

function save(id) {
    let group_id = $("#group_id").val();

    var form_post = (id == 'detail')
        ? "setting=" + queryStringToJSON($("#" + id + " form").serialize())
        : $("#" + id + " form").serialize();
    form_post += "&action=update_agent_sms_setting&group_id=" + group_id + "&type=" + id;
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
            switchEditOrNotEdit(id, 0);
        },
        error: function(err) {
            alert('error');
        }
    });
}

function switchEditOrNotEdit(id, isEditMode) {
    $("#" + id).removeClass("disabledEdit");
    $("#" + id).find(".js_SwitchToEdit").hide();
    $("#" + id).find(".js_Cancel").hide();
    $("#" + id).find(".js_Save").hide();

    if (isEditMode == 1) {
        $("#" + id).find(".js_Cancel").show();
        $("#" + id).find(".js_Save").show();
        $("#" + id).find("input").removeAttr("disabled");
    } else {
        $("#" + id).addClass("disabledEdit");
        $("#" + id).find(".js_SwitchToEdit").show();
        $("#" + id).find("input").attr("disabled", "disabled");
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

.sub-category {
    padding: 0px 0px 0px 20px;
    margin-bottom: 5px;
}

.sub-category span,
.sub-category input {
    display: inline-block;
}

.language-label {
    display: inline-block;
    width: 125px;
    color: #999;
}

.disabledEdit .radio-label {
    opacity: .5;
}
</style>