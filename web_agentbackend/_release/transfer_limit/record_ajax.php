<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

$data = array();

$data['agent_name'] = isset($_GET['agent_name']) ? $_GET['agent_name'] : "";
$data['agent_group_id'] = isset($_GET['agent_group_id']) ? $_GET['agent_group_id'] : "";
$data['type'] = isset($_GET['type']) ? $_GET['type'] : "";

$start_time = isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d H:i:s', strtotime('-1day'));
$end_time = isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d H:i:s');

$data['start_time'] = date('Y-m-d H:i:s', strtotime($start_time . '-8hour'));
$data['end_time'] = date('Y-m-d H:i:s', strtotime($end_time . '-8hour'));;
$data['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$data['per_page'] = 20;

$result = curlPost('api/transfer_limit/getRecord', $data);
$agent_group = $result['data'];

if ($record['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
$page = new CreatePage();
?>
<!-- 表格 遊戲記錄-->
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <div class="table-overflow">
                <div id="dt-opt_wrapper" class="dataTables_wrapper no-footer">
                    <!-- <table id="dt-opt" class="table table-lg table-hover"> -->
                    <table class="table table-lg table-hover no-footer">
                        <thead>
                        <tr>
                            <th><?= $lang['time'] ?></th>
                            <th><?= $lang['group_name'] ?></th>
                            <th><?= $lang['amount'] ?></th>
                            <th><?= $lang['before_point'] ?></th>
                            <th><?= $lang['change_point'] ?></th>
                            <th><?= $lang['memo'] ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        if (count($agent_group) > 0) {
                            $i = 0;
                            foreach ($agent_group as $row) {
                                $row = (array)$row;
                                $isAgent_to_Agent = $row["isAgent_to_Agent"];
                                $prefixText = "(" . ($isAgent_to_Agent ? $lang["agent"] : $lang["player"]) . ")";
                                $type_name = $lang[$row["type_name_lang_key"]] . $prefixText;
                                $status_text = $isAgent_to_Agent ? $lang["status_" . $row["status"]] : $lang["chat_transaction_status_" . $row["status"]];
                                $i++;
                                ?>
                                <tr>
                                    <td>
                                        <div class="mrg-top-15">
                                            <span><?= date('Y-m-d H:i:s', strtotime($row['created_at'] . '+8hour')) ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="mrg-top-15" title="<?= $row['id'] ?>">
                                        <span>
                                            <?= $row['agent_group_name'] ?>
                                        </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="mrg-top-15">
                                            <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                if ($row['amount'] < 0) {
                                                    amount_thousands_off($row['amount'], 2, 'red');
                                                } else {
                                                    amount_thousands_off($row['amount'], 2, 'green');
                                                }
                                            } else {
                                                if ($row['amount'] < 0) {
                                                    amount_thousands_on(-$row['amount'], 2, 'red');
                                                } else {
                                                    amount_thousands_on($row['amount'], 2, 'green');
                                                }
                                            } ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="mrg-top-15">
                                            <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                amount_thousands_off($row['before_limit']);
                                            } else {
                                                amount_thousands_on($row['before_limit']);
                                            }?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="mrg-top-15">
                                            <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                amount_thousands_off($row['after_limit']);
                                            } else {
                                                amount_thousands_on($row['after_limit']);
                                            }?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="mrg-top-15">
                                            <span><?= $row['memo'] ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else { ?>
                            <tr>
                                <td colspan="12" align='center'>
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
                <?php
                if (count($agent_group) > 0) {
                    ?>
                    <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                        <?= $lang['total'] ?> <?= $result['pagination']['total'] ?> <?= $lang['count'] ?>
                    </div>
                    <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                        <?= $page->createPager($result['pagination']['pages'], $result['pagination']['current'], $lang) ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
<script>

</script>
