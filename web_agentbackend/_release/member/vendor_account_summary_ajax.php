<?php
require_once '../_init.php';

$queries = array();
parse_str($_SERVER['QUERY_STRING'], $queries);

$result = curlPost('api/vendor/account/summary', $_GET);

 if ($result['code'] != 1000) {
     echo display_error_msg_html($result["message"], false);
     exit;
 }

$querySummary = $result['data'];


$data = array();

$data['member_username'] =  $_GET["username"];
$data['start_date'] = date('Y-m-d', strtotime('-7day'));
$data['end_date'] = date('Y-m-d');
$data['status'] = 0;
$data['page'] =1;
$data['per_page'] = 30;

$api_sr06 = curlPost('api/report/sr06', $data);

if ($api_sr06['code'] >= 9997) {
    echo display_error_msg_html($api_response["message"], false);
    exit();
}

?>


<div class="col-md-12">
    <div class="card">
        <div class="card-block">


            <div class="row">

                <div class="col-md-3">
                    <span class="label-title">
                        <b><?= $lang["member_account"] ?>：</b>
                    </span>

                    <a href="/member/profile.php?id=<?= $querySummary["member"]["member_id"] ?>" target="_blank"
                        class="linkText">
                        <?= $querySummary["member"]["member_username"] ?>
                    </a>
                    (<?=$querySummary["member"]["member_id"] ?>)
                </div>

                <div class="col-md-2">
                    <span class="label-title">
                        <b> <?= $lang["agent"] ?>：</b>
                    </span>

                    <a href="/agent/profile.php?id=<?= $querySummary["member"]["agent_id"] ?>" target="_blank"
                        class="linkText">
                        <?=$querySummary["member"]["agent_name"] ?>
                    </a>

                    (<?=$querySummary["member"]["agent_id"] ?>)
                </div>

                <div class="col-md-2">
                    <span class="label-title">
                        <b> 個性化組別：</b>
                    </span>

                    <a href="/agent/agent_customized_setting.php?group_id=<?= $querySummary["member"]["customized_setting_group_id"] ?>"
                        target="_blank" class="linkText">
                        <?= $querySummary["member"]["customized_setting_group_id"] ?>
                    </a>

                </div>

                <div class="col-md-2">
                    <span class="label-title">
                        <b> 客服db資訊：</b>
                    </span>

                    <div class="link-text" onclick="showChatDBModal()">
                        查看
                    </div>

                </div>

                <div class="col-md-2">

                    <div style="padding: 3px;font-size:14px;">
                        <input type="checkbox" id="is_auth_1">
                        <label for="is_auth_1" style="margin-left:7px;">
                            GCP網址包含authuser=1
                        </label>
                    </div>

                </div>


            </div>


            <hr>

            <h3>
                <b>
                    各遊戲商遊戲帳號
                </b>
            </h3>

            <div class="row">

                <div class="vendor-account-info">


                    <div class="col-md-12 headerRow">


                        <div class="each-section">
                            <?= $lang["status"]?>
                        </div>

                        <div class="each-section s-size">
                            <?= $lang["id"]?>
                        </div>

                        <div class="each-section">
                            <?= $lang["label_vendor"]?>
                        </div>

                        <div class="each-section l-size">
                            <?= $lang["label_vendor"]?><?= $lang["account_name"]?>
                        </div>

                        <div class="each-section l-size">

                        </div>

                    </div>


                    <?php foreach ($querySummary["vendor_accounts"] as  $item) {?>

                    <div class="col-md-12" style="border-bottom: solid 1px #EEE;">


                        <div class="each-section">

                            <?php if ($item["vendor_status"] == 0) { ?>
                            <span class="status_label disable_style">
                                <?=$lang["disable"] ?>
                            </span>
                            <?php } else { ?>
                            <span class="status_label">
                                <?=$lang["enable"] ?>
                            </span>
                            <?php } ?>
                        </div>

                        <div class="each-section s-size">
                            <?= $item["vendor_id"] ?>
                        </div>

                        <div class="each-section vendorName">
                            <?= $item["vendor_name"] ?>
                        </div>


                        <div class="each-section l-size allow-copy" title="Copy"
                            onclick="copyText('<?= $item["vendor_username"] ?>')">
                            <?= $item["vendor_username"] ?>
                        </div>


                        <div class="each-section l-size">

                            <?php
                                $previous_hours_utc =  gmdate('Y-m-d H:i:00', strtotime('- 1 hours'));
                                $previous_hours_utc =  str_replace(' ', 'T', $previous_hours_utc).'Z';

                                $previous_4_hours_utc =  gmdate('Y-m-d H:i:00', strtotime('- 4 hours'));
                                $previous_4_hours_utc =  str_replace(' ', 'T', $previous_4_hours_utc).'Z';

                                $queryExpress_1_hours = $item["vendor_username"]." AND timestamp ".urlencode('>="'.$previous_hours_utc.'"');
                                $queryExpress_4_hours = $item["vendor_username"]." AND timestamp ".urlencode('>="'.$previous_4_hours_utc.'"');

                            ?>

                            <?php if ($item["vendor_username"] != '-') { ?>

                            <div onclick="open_new_link('https://example.invalid/logs/query;query=<?= $item["vendor_username"] ?>?project=<?= $querySummary["commonInfo"]["gcp_project_id"] ?>&query=%0A')"
                                target="_blank" class="linkText" style="margin-right:15px;">
                                GCP
                            </div>

                            <div onclick="open_new_link('https://example.invalid/logs/query;query=<?= $queryExpress_1_hours ?>?project=<?= $querySummary["commonInfo"]["gcp_project_id"] ?>&query=%0A')"
                                target="_blank" class="linkText" style="margin-right:15px;">
                                GCP(1小時)
                            </div>

                            <div onclick="open_new_link('https://example.invalid/logs/query;query=<?= $queryExpress_4_hours ?>?project=<?= $querySummary["commonInfo"]["gcp_project_id"] ?>&query=%0A')"
                                target="_blank" class="linkText">
                                GCP(4小時)
                            </div>

                            <?php  } ?>
                        </div>



                        <div class="each-section xl-size">
                            <?php if ($item["vendor_status"] == 1 && $item["vendor_username"] != '-') { ?>
                                <button type="button" class="btn btn-inverse btn-danger" style="padding:5px 15px;"
                                        onclick="get_vendor_balance('<?= $querySummary["member"]["member_id"] ?>' , '<?=$item["vendor_id"]?>')">
                                    遊戲錢包
                                </button>
                                <?php
                                if ($item["vendor_id"] == 22) {
                                ?>
                                <button type="button" class="btn btn-inverse btn-warning" style="padding:5px 15px;"
                                        onclick="get_vendor_transfer_in('<?= $item["vendor_username"] ?>','<?= $item["vendor_id"] ?>', '<?= $item["vendor_info_id"] ?>')">
                                    取回餘額
                                </button>
                            <?php
                                }
                            } else {
                                ?>
                            <button type="button" class="btn btn-grey" style="padding:5px 15px;" disabled="disabled">
                                未開通
                            </button>

                            <?php  } ?>



                            <?php  if ($item["vendor_id"] == 24) {?>

                            <a target="_blank"
                                href="https://example.invalid/games-management/record?playerName=<?= $item["vendor_username"] ?>">

                                <button type="button" class="btn btn-primary btn-inverse" style="padding:5px 15px;">
                                    PGS投注紀錄
                                </button>

                            </a>

                            <a target="_blank"
                                href="https://example.invalid/members-management/money-record?playerName=<?= $item["vendor_username"] ?>">

                                <button type="button" class="btn btn-primary btn-inverse " style="padding:5px 15px;">
                                    PGS金幣紀錄
                                </button>

                            </a>


                            <?php  } ?>

                        </div>


                    </div>

                    <?php } ?>

                </div>

            </div>


            <h3 style="margin-top: 35px;">
                <b>
                    近期7日遊戲轉帳紀錄 (前30筆)
                </b>
            </h3>

            <div class="row" style="margin-top:30px;">

                <div class="col-md-12">
                    <div class="transfer-logs">


                        <div class="table-overflow">
                            <!-- <table id="dt-opt" class="table table-lg table-hover"> -->
                            <table class="table table-lg table-hover">
                                <thead>
                                    <tr>
                                        <th><?= $lang['num'] ?></th>
                                        <th><?= $lang['ordernum'] ?></th>
                                        <th><?= $lang['date'] ?></th>
                                        <th><?= $lang['agent_account'] ?></th>
                                        <th><?= $lang['platform'] ?></th>
                                        <th><?= $lang['type'] ?></th>
                                        <th><?= $lang['member_account'] ?></th>
                                        <th><?= $lang['member_nickname'] ?></th>
                                        <th class="text-right"><?= $lang['change_point'] ?></th>
                                        <th class="text-right"><?= $lang['moment_point'] ?></th>
                                        <th><?= $lang['status'] ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php

                        if (count($api_sr06['data']) > 0) {
                            foreach ($api_sr06['data'] as $row) {
                                $matchVendorAccount = $querySummary["vendor_accounts_mappingDic"][$row["vendor_id"]];

                                //時間篩選前後1分鐘的三方API請求紀錄
                                $previous_minutes =  date('Y-m-d H:i:s', strtotime($row['created_at_utc']. '-1 minutes'));
                                $previous_minutes = str_replace(' ', 'T', $previous_minutes).'Z';
                                $after_minutes =  date('Y-m-d H:i:s', strtotime($row['created_at_utc']. '+1 minutes'));
                                $after_minutes = str_replace(' ', 'T', $after_minutes).'Z';
                                $queryExpressOfTransaction = $matchVendorAccount["vendor_username"].
                                " AND timestamp ".urlencode('>="'.$previous_minutes.'"').
                                " AND timestamp ".urlencode('<="'.$after_minutes.'"'); ?>

                                    <tr>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?= $row['id'] ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">

                                                <div class="link-text" target="_blank"
                                                    onclick="open_new_link('https://example.invalid/logs/query;query=<?= $queryExpressOfTransaction ?>?project=<?= $querySummary["commonInfo"]["gcp_project_id"] ?>&query=%0A')">
                                                    <?= $row['transaction_no'] ?>
                                                </div>
                                                (GCP日誌)
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?= $row['created_at'] ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?= $row['agent_username'] ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><b><?= $row['vendor'] ?></b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?= ($row['type'] == 1 ? $lang['transferout'] : $lang['transferin']) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class=""><b><?= $row['member_username'] ?></b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?= $row['member_nickname'] ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15 text-right">
                                                <?php if ($row['type'] == 1) { ?>
                                                <span style='color:red'><?= number_format(-$row['point'], 2) ?></span>
                                                <?php } else { ?>
                                                <span><?= number_format($row['point'], 2) ?></span>
                                                <?php } ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15 text-right">
                                                <span><?= number_format($row['balance'], 2) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span
                                                    class="<?= ($row['status'] == 1000) ? 'text-success' : 'text-danger' ?>">
                                                    <?= ($row['status'] == 1000) ? $lang['success'] : $lang['failure'] ?>
                                                    <?= ($row['status'] == 9000 || $row['status'] == 9990) ? '(' . $lang['returned'] . ')' : '' ?>
                                                </span>
                                                <?php if ($row['status'] == 9995 and $row['point'] <= 1000 and $_SESSION['host_id'] == 33893) { ?>
                                                <a class="btn btn-outline-danger btn-sm refund"
                                                    data-uid="<?= $row['id'] ?>"><?= $lang['compensate'] ?></a>
                                                <?php } ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                            }
                        } else { ?>
                                    <tr>
                                        <td colspan="10" align='center'>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><b><?= $lang['no_record'] ?></b></span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php }
                        ?>
                                </tbody>
                            </table>
                        </div>


                    </div>

                </div>

            </div>


        </div>
    </div>
</div>



<div id="showChatDBInfoModal" class="modal fade" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="width:850px;">
            <div class="modal-header">
                <h5 class="modal-title">
                    <b>
                        客服DB資訊 (ChatDB)
                    </b>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" style="padding:35px;">


                <div>

                    <div class="row dash-style" style="padding: 20px 50px;">

                        <div class="col-md-6">
                            <div class="label-text">此會員的member_id (ChatDB) </div>
                            <div class="value-text">
                                <?= $querySummary["chatDB_member"]["member_id"] ?>
                            </div>
                        </div>

                        <div class="col-md-6">

                            <div class="label-text">上級代理 </div>
                            <div class="value-text">
                                <?= $querySummary["member"]["agent_name"] ?>
                            </div>


                        </div>

                        <div class="col-md-6">
                            <div class="label-text">
                                上級代理的member_id (ChatDB)
                            </div>
                            <div class="value-text">
                                <?= $querySummary["chatDB_member"]["parent_agent_member_id"] ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="label-text">
                                上級代理的user_id (ChatDB)
                            </div>
                            <div class="value-text">
                                <?= $querySummary["chatDB_member"]["parent_agent_user_id"] ?>
                            </div>
                        </div>


                        <div class="col-md-6">
                            <div class="label-text">
                                聊天組別
                            </div>
                            <div class="value-text">
                                <?= $querySummary["member"]["chat_group_id"] ?>
                            </div>
                        </div>




                        <div class="col-md-6">
                            <div class="label-text">
                                個性化組別 (紅包開關 etc...)
                            </div>
                            <div class="value-text">
                                <a href="/agent/agent_customized_setting.php?group_id=<?= $querySummary["member"]["customized_setting_group_id"] ?>"
                                    target="_blank" class="linkText">
                                    <?= $querySummary["member"]["customized_setting_group_id"] ?>
                                </a>
                            </div>
                        </div>



                    </div>


                </div>


            </div>

            <div class="modal-footer">

            </div>
        </div>
    </div>
</div>



<script>
$(document).ready(function() {

    set_default_check_by_cookie();

    $("#is_auth_1").change(function() {

        if ($(this).is(":checked")) {
            Cookies.set('is_auth_1', '1');

        } else {
            Cookies.set('is_auth_1', '0');
        }
    });

});




function get_vendor_balance(member_id, vendor_id) {

    $.blockUI();

    let post = "action=get_vendor_balance&member_id=" + member_id + "&vendor_id=" + vendor_id;

    $.post("<?= BASE_URL ?>ajax.php", post, function(data) {
        $.unblockUI();

        if (data.code == 1000) {

            let text = JSON.stringify(data.data);
            alert('成功取得 API_Frontend 回應' + '\n' + text + '\n' + '若有疑問建議查看GCP日誌');

        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');
}

function get_vendor_transfer_in(account, vendor_id, vendor_info_id) {
    $.blockUI();

    let post = "action=get_vendor_transfer_in&account=" + account +
        "&vendor_id=" + vendor_id +
        "&vendor_info_id=" + vendor_info_id;
    $.post("<?= BASE_URL ?>ajax.php", post, function(data) {
        console.log('data:', data);
        $.unblockUI();
        if (data.code == 1000) {
            let text = JSON.stringify(data.message);
            alert('成功取得 API_Internal 回應' + '\n' + text);
        } else {
            alert(data.message);
        }
    }, 'json');
}


function set_default_check_by_cookie() {

    let last_value = Cookies.get('is_auth_1');

    if (last_value === undefined || last_value == null || last_value == '') {
        return;
    }

    if (last_value == "1") {
        $("#is_auth_1").prop('checked', true);
    }
}



function open_new_link(url) {

    let finalUrl = url;

    let isAuthuser1 = $("#is_auth_1").is(":checked");

    if (isAuthuser1) {
        finalUrl = url + "&authuser=1";
    }

    window.open(
        finalUrl,
        '_blank'
    );
}


function copyText(text) {
    clipboardHelper.copyText(text);
    successCopyAlert();
}



function showChatDBModal() {
    $("#showChatDBInfoModal").modal("show");
}
</script>


<style>
@media (min-width: 1200px) {
    .modal-xl {
        max-width: 1140px;
    }
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
</style>