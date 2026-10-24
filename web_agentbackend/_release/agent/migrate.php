<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//檢查權限
if (!isset($check_auth['agent/migrate'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$isAdmin = $_SESSION['id'] == 1;

$res_agents = curlPost('api/agent/dropdownList');
$agents = $res_agents['data'];

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
                <h4><?= $lang['agnet_management'] ?> > <?= $lang['menu_migrate_agent'] ?></h4>
            </div>
            <!-- <a href="edit.php" class="btn btn-inverse btn-success"><?= $lang['add_agent'] ?></a> -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">




                            <div class="row">


                                <div class="col-md-3">

                                    <div>
                                        要針對哪一位代理做移轉
                                    </div>

                                    <select id="target_agent_id" name="target_agent_id" class="selectized_style">

                                        <option value="" selected><?= $lang["please_choose"] ?></option>
                                        <?php foreach ($agents as $row) { ?>

                                        <option value="<?= $row['id'] ?>"><?= $row['username'] ?></option>

                                        <?php } ?>

                                    </select>

                                </div>



                                <div class="col-md-3">

                                    <div>
                                        要移轉到哪位代理底下
                                    </div>

                                    <select id="new_parent_agent_id" name="new_parent_agent_id"
                                        class="selectized_style">

                                        <option value="" selected><?= $lang["please_choose"] ?></option>
                                        <?php foreach ($agents as $row) { ?>

                                        <option value="<?= $row['id'] ?>"><?= $row['username'] ?></option>

                                        <?php } ?>

                                    </select>
                                </div>



                                <div class="col-md-6"></div>



                                <div class="col-md-12">


                                    <div style="text-align:center">
                                        <button type="button" onClick="gerPreviewResult()"
                                            class="btn btn-info btn-inverse" style="float:left;margin-top:15px;">
                                            顯示預覽結果
                                        </button>


                                        <a class="link-text" onClick="triggerCheckHierarchyResult()"
                                            style="float: left;margin-top: 30px;margin-left: 15px;color: #888 !important;">
                                            檢查全體代理樹狀異常
                                        </a>
                                        <!-- <button type="button" onClick="triggerCheckHierarchyResult()"
                                            class="btn btn-info btn-inverse" style="float:left;margin-top:15px;">
                                            檢查全體代理樹狀異常
                                        </button> -->

                                    </div>


                                </div>

                            </div>


                            <hr>

                            <div class="col-md-12">
                                <div id="previewResult">
                                </div>
                            </div>

                        </div>


                    </div>



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

    $('.selectized_style').selectize({
        create: false,
        dropdownParent: 'body'
    });

    $('#target_agent_id').change(function() {
        $("#previewResult").html("");
    });

    $('#new_parent_agent_id').change(function() {
        $("#previewResult").html("");
    });



});




function gerPreviewResult() {

    let target_agent_id = $("#target_agent_id").val().trim();
    let new_parent_agent_id = $("#new_parent_agent_id").val().trim();

    let query = "?target_agent_id=" + target_agent_id + "&new_parent_agent_id=" + new_parent_agent_id;

    $.get('/agent/migrate_preview.php' + query, function(data) {
        $("#previewResult").html(data);
    });
}


function triggerCheckHierarchyResult() {

    $.blockUI();

    let form = "action=check_hierarchy";
    $.post("<?= BASE_URL ?>ajax.php", form, function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            var unexpected_agentIDs = data.data.unexpected_agentIDs;

            console.log(data);

            if (unexpected_agentIDs != null && unexpected_agentIDs.length > 0) {
                let msg = "(警告)偵測到以下代理樹狀結構異常，" + data.data.unexpected_agentIDsText;

                alert(msg);
            } else {
                alert(data.message + ', 無異常');
            }

        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');


}
</script>



<style>
.selectized_style {
    width: 300px;
}
</style>