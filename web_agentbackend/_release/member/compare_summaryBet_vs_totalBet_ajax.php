<?php
require_once '../_init.php';

$queries = array();
parse_str($_SERVER['QUERY_STRING'], $queries);

$result = curlPost('api/record/compare/summary-vs-total', $_GET);

if ($result['code'] != 1000) {
    echo display_error_msg_html($result["message"], false);
    exit;
}

$querySummary = $result["data"];

?>


<div class="col-md-12">
    <div class="card">
        <div class="card-block">


            <div class="row">

                <div class="col-md-2">
                    <span class="label-title">
                        <b><?= $lang["member_account"] ?>：</b>
                    </span>

                    <a href="/member/profile.php?id=<?= $querySummary["member_id"] ?>" target="_blank" class="linkText">
                        <?= $querySummary["member_username"] ?>
                    </a>

                    (<?=$querySummary["member_id"] ?>)
                </div>

                <div class="col-md-2">
                    <span class="label-title">
                        <b> <?= $lang["agent"] ?>：</b>
                    </span>

                    <a href="/agent/profile.php?id=<?= $querySummary["agent_id"] ?>" target="_blank" class="linkText">
                        <?=$querySummary["agent_username"] ?>
                    </a>

                    (<?=$querySummary["agent_id"] ?>)
                </div>

                <div class="col-md-2">

                    <span class="label-title">
                        <b> vendor_info_id：</b>
                    </span>

                    <?=$querySummary["vendor_info_id"] ?>

                </div>


                <div class="col-md-2">

                    <span class="label-title">
                        <b> 個性化組別:</b>
                    </span>

                    <a target="_blank" class="link-text"
                        href="/agent/agent_customized_setting.php?group_id=<?=$querySummary["customized_setting_group_id"] ?>">
                        <?=$querySummary["customized_setting_group_id"] ?>
                    </a>

                </div>


                <div class="col-md-2">

                    <span class="label-title">
                        <b> hierarchy_path: </b>
                    </span>

                    <?=$querySummary["hierarchy_path"] ?>

                </div>



                <div class="col-md-2">
                </div>

            </div>


            <hr>

            <h3 style="margin-top:50px;">

                每日數據 (summaryBetRecord ／ totalBetRecord)

            </h3>

            <div class="row">

                <div class="vendor-account-info">


                    <div class="col-md-12 headerRow">


                    </div>

                    <table class="table" style="width:700px;">
                        <tr>
                            <th>
                                日期 (UTC+0)
                            </th>
                            <th class='text-r'>
                                投注金額
                            </th>
                            <th class='text-r'>
                                輸贏金額
                            </th>
                        </tr>



                        <?php foreach ($result["data"]["dateSummary"] as $item) { ?>

                        <tr>
                            <td>
                                <?= $item["date"] ?>
                            </td>
                            <td class='text-r <?= $item["isBetAmountEqual"] ? "" : "redWarning" ?>'>
                                <span title="summary_bet_record">
                                    <?= number_format($item["betAmount_summaryBet"] , 2) ?>
                                </span>
                                ／
                                <span title="total_bet_record"><?= number_format($item["betAmount_totalBet"], 2) ?>
                                </span>
                            </td>
                            <td class='text-r <?= $item["isWinLoseEqual"] ? "" : "redWarning" ?>'>
                                <span title="summary_bet_record"><?= number_format($item["winlose_summaryBet"], 2) ?>
                                </span>
                                ／
                                <span title="total_bet_record">
                                    <?= number_format($item["winlose_totalBet"], 2) ?>
                                </span>
                            </td>
                        </tr>

                        <?php } ?>

                    </table>





                    <?php if($result["data"]["gameSummary"] != null && count($result["data"]["gameSummary"]) > 0) { ?>


                    <h3 style="margin-top:50px;">

                        每款遊戲數據

                    </h3>

                    <table class="table" style="width:1100px;">
                        <tr>
                            <th>
                                gameID
                            </th>
                            <th>
                                遊戲名稱
                            </th>
                            <th class='text-r'>
                                是否算投注活動
                            </th>
                            <th class='text-r'>
                                有效投注轉換比例
                            </th>
                            <th class='text-r'>
                                投注金額
                            </th>
                            <th class='text-r'>
                                輸贏金額
                            </th>
                            <th class='text-r'>
                                總筆數
                            </th>
                        </tr>


                        <?php foreach ($result["data"]["gameSummary"] as $item) { ?>

                        <tr>
                            <td>
                                <?= $item["game_id"] ?>
                            </td>
                            <td>
                                <?= $item["game_name"] ?>
                            </td>

                            <td class='text-r'>

                                <?php if($item["is_setting_exist"] == 0) { ?>

                                <span style="background: #e06161;color: #FFF;padding: 5px;border-radius: 5px;">
                                    無配置
                                </span>

                                <?php } else { ?>

                                <?= $item["is_join_bet_event"] == 1 ? "v" : "" ?>

                                <?php } ?>
                            </td>

                            <td class='text-r'>

                                <?php if($item["is_setting_exist"] == 0) { ?>

                                <span style="background: #e06161;color: #FFF;padding: 5px;border-radius: 5px;">
                                    無配置
                                </span>


                                <?php } else { ?>


                                <?= $item["valid_bet_rate"] ?>
                                <?php } ?>


                            </td>

                            <td class='text-r'>
                                <?= number_format( $item["bet"] , 2 ) ?>
                            </td>
                            <td class='text-r'>
                                <?= number_format( $item["win_lose"], 2 ) ?>
                            </td>
                            <td class='text-r'>
                                <?=  $item["total_count"]  ?>
                            </td>
                        </tr>

                        <?php } ?>

                    </table>


                    <?php  } ?>



                    <div style="margin-top:75px;">
                        <h3>
                            常見錯誤原因：
                        </h3>


                        <div
                            style="background: #fff4e2;border-radius: 5px;padding: 10px 35px 10px 0px;display: inline-block;">

                            <ul>
                                <li>summary_bet 與 total_bet不一致</li>
                                <li>gameID為0</li>
                                <li>特定遊戲不算投注活動</li>
                                <li>投注資料根本沒抓回來(對方注單API出錯?)</li>
                                <li>918KISS、Mega888、Pussy888 summary_bet 只能精確到日期單位</li>
                            </ul>
                        </div>

                    </div>


                </div>

            </div>



        </div>
    </div>
</div>




<script>
$(document).ready(function() {


});
</script>


<style>
.table {
    margin-left: 20px;
}


@media (min-width: 1200px) {
    .modal-xl {
        max-width: 1140px;
    }
}

.redWarning {
    color: #FF0000;
}

.label-title {
    font-size: 18px;
    color: #515365;

}

.each-section {
    display: inline-block;
    padding: 10px;
    width: 150px;
}


.status_label {
    border-radius: 5px;
    padding: 5px 10px;
    color: #FFF;
    background: #7abc7f;
}


.status_label.disable_style {
    background: #DDD;
}

.each-section.s-size {
    width: 75px;
}

.each-section.l-size {
    width: 275px;
}

.each-section.xl-size {
    width: 400px;
}

.vendor-account-info {
    padding: 25px;
    width: 100%;
}

.vendor-account-info .btn {
    float: none !important;
}


.vendor-account-info .col-md-12:hover {
    background: #f6f6f6;
}

.vendor-account-info .col-md-12:hover .vendorName {
    font-weight: 700;
    color: #2ba034;
}

.headerRow div {
    font-weight: 700;
}


.each-section .btn {
    min-width: 100px;
    margin-bottom: 0px !important;
}


.each-section .btn-grey {
    background: #EEE !important;
}


h3 {
    color: #4f98cc;
}
</style>