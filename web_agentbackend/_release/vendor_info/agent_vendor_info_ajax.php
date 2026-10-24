<?php
require_once '../_init.php';

//查詢條件的代理列表
$apiResult = curlPost('api/vendor_info/find-by-agent', $_GET);

if($apiResult["code"] != 1000)
{
    echo display_error_msg_html($apiResult["message"] , false);
    exit;
}

$vendors = $apiResult["data"];

?>

<div style="padding: 0px 25px;">

    <div>

        <div class="row dash-style">

            <div class="col-md-6">
                <div class="label-text">代理ID</div>
                <div class="value-text">
                    <?= $vendors["agent_id"] ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="label-text">代理帳號</div>
                <div class="value-text">
                    <?= $vendors["agent_username"] ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="label-text">hierarchy_path</div>
                <div class="value-text">
                    <?= $vendors["hierarchy_path"] ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="label-text">引用哪個代理配置</div>
                <div class="value-text">

                    <?php foreach ($vendors["vendor_info_source_agents"] as $key => $value) { ?>

                    <span> <?= $value ?> </span>

                    <?php } ?>

                </div>
            </div>


        </div>


        <div>

            <h4>
                <b>
                    各遊戲商對應的vendor_info_id
                </b>
            </h4>
        </div>

        <div style="padding:15px 35px;">


            <div style="width:100px;display:inline-block">
                遊戲商ID
            </div>

            <div style="width:150px;display:inline-block">
                遊戲商名稱
            </div>

            <div style="width:125px;display:inline-block">
                vendor_info_id
            </div>

            <div style="width:200px;display:inline-block">
                名稱
            </div>

            <?php foreach ($vendors["vendor_info_list"] as $key => $item) { ?>


            <div class="eachv-endor-row">

                <div style="width:100px;display:inline-block">
                    <?= $item["vendor_id"] ?>
                </div>

                <div style="width:150px;display:inline-block">
                    <?= $item["vendor_name"] ?>
                </div>

                <div style="width:125px;display:inline-block" title="<?= $item["source_agent"] ?>">
                    <?= $item["vendor_info_id"] ?>
                </div>

                <div style="width:200px;display:inline-block" title="<?= $item["source_agent"] ?>">
                    <?= $item["vendor_info_name"] ?>
                </div>

            </div>


            <?php } ?>

        </div>


    </div>


</div>

<style>
.eachv-endor-row:hover {
    background: #F5F5F5;
}


.eachv-endor-row>div {
    padding: 2px;
}
</style>