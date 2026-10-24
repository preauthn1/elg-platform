<?php

require_once '../_init.php';


$post = array();
$post["target_agent_id"] = $_GET['target_agent_id'] ;
$post["new_parent_agent_id"] = $_GET['new_parent_agent_id'];

$api_result = curlPost('api/agent/hierarchy-migrate/preview', $post);

$isShowError = false;

if ($api_result["code"] != 1000) {
    $isShowError = true;
}

$preview = $api_result['data'];

?>




<?php if (!$isShowError) { ?>


<div style="margin-top:35px;">


    <div class="text-l" style="margin-bottom:30px;">

        <h1>
            <b>
                預覽結果
            </b>
        </h1>

    </div>

    <div class="row panel">


        <div class="col-md-3" style="margin-top: 0px;">

            <div>

                <div class="card">
                    <div class="card-block preview_agent_info">

                        <div>
                            <b>
                                <span class="agent_name">
                                    <?= $preview ["target_agent"]["username"] ?>
                                </span>
                            </b>

                            <div>
                                <span class="agent_id">
                                    <?= $preview ["target_agent"]["agent_id"] ?>
                                </span>
                            </div>
                        </div>

                        <div>
                            <?= $preview ["target_agent"]["hierarchy"] ?>
                        </div>
                        <div style="color:#e88080">
                            <?= $preview ["target_agent"]["hierarchy_path"] ?>
                        </div>
                    </div>
                </div>

            </div>


        </div>

        <div class="col-md-3">


            <div class="card">
                <div class="card-block preview_agent_info">

                    <span class="new-tag">New!</span>

                    <div class="fixed-label">
                        新上級代理
                    </div>

                    <div>
                        <b>
                            <span class="agent_name">
                                <?= $preview ["new_parent_agent"]["username"] ?>
                            </span>
                        </b>

                        <div>
                            <span class="agent_id">
                                <?= $preview ["new_parent_agent"]["agent_id"] ?>
                            </span>
                        </div>
                    </div>

                    <div>
                        <?= $preview ["new_parent_agent"]["hierarchy"] ?>
                    </div>

                    <div style="color:#e88080">
                        <?= $preview ["new_parent_agent"]["hierarchy_path"] ?>
                    </div>
                </div>
            </div>




        </div>



        <div class="col-md-3">

            <div class="card">
                <div class="card-block preview_agent_info before">

                    <div class="fixed-label">
                        原上級代理
                    </div>

                    <div>
                        <b>
                            <span class="agent_name">
                                <?= $preview ["original_agent"]["username"] ?>
                            </span>
                        </b>

                        <div>
                            <span class="agent_id">
                                <?= $preview ["original_agent"]["agent_id"] ?>
                            </span>
                        </div>
                    </div>

                    <div>
                        <?= $preview ["original_agent"]["hierarchy"] ?>
                    </div>

                    <div>
                        <?= $preview ["original_agent"]["hierarchy_path"] ?>
                    </div>

                </div>
            </div>

        </div>



    </div>





    <?php  if (count($preview["hierachyChilds"]) > 0) { ?>


    <div style="margin-top:30px;">

        <h1>
            <b>
                影響下級代理
            </b>
        </h1>


    </div>

    <?php } ?>



    <div class="row panel">

        <div class="col-md-12">

            <?php foreach ($preview["hierachyChilds"] as $item) {?>

            <div>
                <span class="info">
                    <?= $item["agent_id"] ?>
                </span>

                <span class="info">
                    <?= $item["username"] ?>
                </span>

                <span class="info" style="min-width: 200px;">
                    <?= $item["hierarchy"] ?>
                </span>

                <span class="info" style="min-width: 200px;">
                    <?= $item["hierarchy_path"] ?>
                </span>


                <span class="info">
                    預計轉換為 =>
                </span>

                <span class="info" style="color:#5e974b">
                    <?= $item["expect_new_path"] ?>
                </span>

            </div>

            <?php } ?>

        </div>

    </div>




    <div style="margin-top:30px;">
        <h1>
            <b>
                SQL預覽
            </b>
        </h1>

    </div>




    <div class="row panel">

        <div class="col-md-12" style="padding: 15px;background: #F3F3F3;border-radius: 5px;">

            <div>

                <?= $preview["sqlRaw_1"] ?>
            </div>

            <div>

                <?= $preview["sqlRaw_2"] ?>
            </div>

            <div>
                <?= $preview["sqlRaw_3"] ?>
            </div>

        </div>

    </div>





    <div style="margin-top:30px;">
        <hr>
    </div>


    <div class="row">

        <div class="col-md-12">


            <button type="button" onClick="executeMigrate()" class="btn btn-danger" style="float:left;margin-top:15px;">
                開始進行移轉
            </button>


        </div>

    </div>


</div>



</div>


<?php } ?>



<?php  if ($isShowError) { ?>

<div style="color:#ff4646">
    <?= $api_result["message"] ?>
</div>

<div>
    <?= $api_result["data"] ?>
</div>

<?php } ?>


<script>
function executeMigrate() {


    let target_id = $("#target_agent_id").val();
    let new_id = $("#new_parent_agent_id").val();


    $msg = "<?= $lang["are_you_sure"] ?>"

    if (confirm($msg)) {

        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        });

        let form = "action=migrate_agent&target_agent_id=" + target_id + "&new_parent_agent_id=" + new_id;
        $.post("<?= BASE_URL ?>ajax.php", form, function(data) {
            $.unblockUI();

            if (data.code == 1000) {

                var unexpected_agentIDs = data.data.unexpected_agentIDs;

                if (unexpected_agentIDs != null && unexpected_agentIDs.length > 0) {
                    let msg = "(警告)偵測到以下代理樹狀結構異常，" + data.data.unexpected_agentIDsText;
                    console.log(msg);
                    alert(msg);
                } else {
                    alert(data.message);
                }

                $("#previewResult").html();

            } else {
                alert(data.message + "." + data.data);
            }
        }, 'json');

    }


}
</script>


<style>
.info {
    min-width: 125px;
    display: inline-block;
}

.new-tag {
    z-index: 99999;
    position: absolute;
    border-radius: 5px;
    border: solid 1px #EEE;
    padding: 2px 10px;
    background: #66c9e7;
    transform: rotate(25deg);
    color: #fff;
    font-weight: 700;
    right: -15px;
    top: -15px;
}

.fixed-label {
    position: absolute;
    border-radius: 5px;
    border: solid 1px #EEE;
    padding: 5px 10px;
    background: #fbfbfb;
    color: #333;
    right: 5px;
    font-weight: 700;
    top: 5px;
}

.preview_agent_info {
    text-align: center;
    position: relative;
}

.agent_name {
    font-size: 24px;
    font-weight: 700;
}

.agent_id {
    font-size: 12px;
    color: #888;
}


.before .agent_id {
    color: #DDD;
}

.before {
    color: #DDD;
}


.panel {
    padding-left: 50px;
}


.text-l {
    text-align: left;
}

.text-c {
    text-align: center;
}

.newParent {
    background: #f6aa38;
    color: #FFF;
}


h1 {
    font-size: 20px;
    border-left: solid 5px #c4e6e1;
    padding-left: 10px;
    margin-bottom: 10px;
}
</style>