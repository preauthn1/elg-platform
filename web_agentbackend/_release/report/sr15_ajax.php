<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

$data = array();

//取對應語言包
$translates = curlPost('api/translate');
$lab_jackpot = isset($translates['data']['3004']) ? $translates['data']['3004'] : 'Jackpot';
$lab_pay_fee = isset($translates['data']['3006']) ? $translates['data']['3006'] : 'Pay fees';
$lab_sms_fee = isset($translates['data']['3007']) ? $translates['data']['3007'] : 'SMS fee';
$lab_cost = isset($translates['data']['3008']) ? $translates['data']['3008'] : 'Cost';

$start_date = isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d H:i:s', strtotime('-1day'));
$end_date = isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d H:i:s');

$data['username'] = isset($_GET['username']) ? $_GET['username'] : "";
$data['parent_username'] = isset($_GET['parent_username']) ? $_GET['parent_username'] : "";
$data['vendor_id'] = isset($_GET['vendor_id']) ? $_GET['vendor_id'] : "";
$data['cost_visible'] = isset($_GET['cost_visible']) ? $_GET['cost_visible'] : "";
$data['start_time'] = $start_date ;
$data['end_time'] = $end_date;
$data['hidden_zero_data'] = isset($_GET['hidden_zero_data']) ? $_GET['hidden_zero_data'] : 1 ;
$data['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$data['per_page'] = isset($_GET['per_page']) ? $_GET['per_page'] : 20;

function isArrayItemExist($array, $type, $username)
{
    $isExist = false;

    foreach ($array as $value) {
        if ($value["type"] == $type && $value["username"] == $username) {
            $isExist = true;
        }
    }

    return $isExist;
}

$search_history =  isset($_SESSION["agents_history"]) ? $_SESSION["agents_history"] : array();

if (IsQueryStringExist('parent_username')) {
    $sessionItem = [
        "type" => 2,
        "username" => $_GET['parent_username'],
    ];
    
    if (!isArrayItemExist($search_history, 2, $_GET['parent_username'])) {
        array_push($search_history, $sessionItem);
    }
} elseif (IsQueryStringExist('username')) {
    $sessionItem = [
        "type" => 1,
        "username" => $_GET['username'],
    ];

    if (!isArrayItemExist($search_history, 1, $_GET['username'])) {
        array_push($search_history, $sessionItem);
    }
} else {
    $search_history = array();
}

//只取最後5筆
$last_items = array_slice($search_history, -5, 5, true);

$_SESSION["agents_history"] = $last_items;

$api_response = curlPost('api/report/sr15', $data);
if ($api_response['code'] != 1000) {
    echo display_error_msg_html($api_response["message"], false);
    exit;
}
$record = $api_response["data"];

$page = new CreatePage();
?>
<?php /*表格 遊戲記錄*/ ?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <div class="table-overflow">
                <div>
                </div>
                <div id="dt-opt_wrapper" class="dataTables_wrapper no-footer">
                    <div class="history" style="">
                        <?php if (IsQueryStringExist('parent_username')) { ?>
                        <div style="display:inline-block;margin-top: 15px;">
                            <b>
                                <?= $lang["unlimit_parent_member"] ?>:
                            </b>
                            <span style="margin-left: 10px;color:#888;">
                                <?= $_GET["parent_username"] ?>
                            </span>
                        </div>
                        <?php } ?>
                        <button type="button" class="btn btn-success" id="excel" style="float:right;margin-left:25px;">Excel</button>
                        <div style="float:right;padding:10px;">
                            <?php if (count($last_items) > 0) { ?>
                            <span style="margin-right:10px;">
                                <b>
                                    <?=$lang["search_history"]?>:
                                </b>
                            </span>
                            <?php }
                            foreach ($last_items as $item) {
                                if ($item["type"] == 1) { ?>
                            <div class="chipItem" onclick="showAgentReportByUsername('<?= $item['username'] ?>')">
                                <?= $item["username"] ?>
                            </div>
                            <?php  } elseif ($item["type"] == 2) { ?>
                            <div class="chipItem" onclick="showChildReport('<?= $item['username'] ?>')">
                                <?= $item["username"] ?> (<?= $lang["check_lower"] ?>)
                            </div>
                            <?php }
                            } ?>
                        </div>
                    </div>
                    <table class="table table-lg table-hover no-footer">
                        <thead>
                            <tr>
                                <th><?= $lang['agent_account'] ?></th>
                                <th><?= $lang['unlimit_parent_member'] ?></th>
                                <th class="text-right"><?= $lang['total_bet'] ?></th>
                                <th class="text-right"><?= $lang['total_winlose'] ?></th>
                                <th class="text-right"><?= $lang['commission'] ?></th>
                                <th class="text-right"><?= $lang['jackpot_event'] ?></th>
                                <?php if ($_REQUEST['cost_visible'] == 1) { ?>
                                    <th class="text-right"><?= $lab_cost ?></th>
                                <?php } ?>
                                <th class="text-right"><?= $lang['v_jackpot'] ?></th>
                                <th class="text-right"><?= $lang['total_score'] ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($record) > 0) {
                                $i = 0;
                                foreach ($record as $row) {
                                    $i++; ?>
                            <tr>
                                <td>
                                    <div class="mrg-top-15">
                                        <span class="linkText" title="<?= $row['agent_id'] ?>"
                                            onclick="showChildReport('<?= $row['username'] ?>')">
                                            <?= $row["username"] ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row["parent_username"] ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['total_bet']);
                                        } else {
                                            amount_thousands_on($row['total_bet']);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right <?= $row["win_lose"] < 0 ? "minus-red": "" ?> ">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['win_lose']);
                                        } else {
                                            amount_thousands_on($row['win_lose']);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['commission']);
                                        } else {
                                            amount_thousands_on($row['commission']);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['jackpot_event']);
                                        } else {
                                            amount_thousands_on($row['jackpot_event']);
                                        }?>
                                    </div>
                                </td>
                                <?php if ($_GET['cost_visible'] == 1) { ?>
                                    <td>
                                        <div class="mrg-top-15 text-right">
                                    <span style='color:<?= ($row['cost'] < 0) ? 'red' : 'blue' ?>'>
                                        <?= number_format($row['cost'], 4) ?>
                                    </span>
                                        </div>
                                    </td>
                                    <?php } ?>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['v_jackpot']);
                                        } else {
                                            amount_thousands_on($row['v_jackpot']);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right <?= $row["total_score"] < 0 ? "minus-red": "" ?>  ">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['total_score']);
                                        } else {
                                            amount_thousands_on($row['total_score']);
                                        }?>
                                    </div>
                                </td>
                            </tr>
                            <?php
                                }
                            } else { ?>
                            <tr>
                                <td colspan="8" align='center'>
                                    <div class="mrg-top-15">
                                        <span class="text-info">
                                            <b>
                                                <?= $lang['no_record'] ?>
                                            </b>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($record) > 0) { ?>
                <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                    <?= $lang['total'] ?> <?= $api_response['pagination']['total'] ?> <?= $lang['count'] ?>
                </div>
                <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                    <?= $page->createPager($api_response['pagination']['pages'], $api_response['pagination']['current'], $lang) ?>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
<style>
span.red {
    color: #dd5050;
}
</style>