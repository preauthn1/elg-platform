<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$data = array();
$data['vendor_id'] = $_GET['vendor_id'] ? $_GET['vendor_id'] : 1;
$data['game_type'] = $_GET['game_type'] ? $_GET['game_type'] : 0;
if ($_GET['type'] == 1 && $_GET['username'] != '') {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 2 && $_GET['username'] != '') {
    $data['member_username'] = $_GET['username'];
} else if ($_GET['type'] == 3 && $_GET['username'] != '') {
    $data['game_no'] = $_GET['username'];
}
$data['game_id'] = $_GET['game_id'] ? $_GET['game_id'] : 0;
$data['sort_by'] = $_GET['sort_by'] ? $_GET['sort_by'] : 1;
$data['whitch_time'] = $_GET['whitch_time'] ? $_GET['whitch_time'] : 1;
$data['page'] = $_GET['page'] ? $_GET['page'] : 1;
$data['per_page'] = $_GET['per_page'] ? $_GET['per_page'] : 25;
$data['start_time'] = ($_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00')) . ':00';
$data['end_time'] = ($_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59')) . ':59';
$record = curlPost('api/report/sr12', $data);
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
                                <!--<th><? #=$lang['num']
                                        ?></th>-->
                                <th><?= $lang['bet_time'] ?></th>
                                <th><?= $lang['agent_account'] ?></th>
                                <th><?= $lang['member_account'] ?></th>
                                <th><?= $lang['game_name'] ?></th>
                                <th class="text-right"><?= $lang['bet'] ?></th>
                                <th class="text-right"><?= $lang['jackpot'] ?></th>
                                <th class="text-right"><?= $lang['winlose'] ?></th>
                                <!-- <th><?= $lang['servcie_charge'] ?></th>                                
                                <th><?= $lang['settle_time'] ?></th>
                                <th><?= $lang['bet_status'] ?></th> -->
                                <th><?= $lang['ordernumber'] ?></th>
                                <!-- <th><?= $lang['memo'] ?></th> -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($record['data']) > 0) {
                                $i = 0;
                                foreach ($record['data'] as $row) {
                                    $i++;
                            ?>
                            <tr>
                                <!--<td>
                                    <div class="mrg-top-15">
                                        <span><?= $i ?></span>
                                    </div>
                                </td>-->
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['bet_time'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['agent_username'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['member_username'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span class="text-info"><b><?= $row['game_name'] ?></b></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <span><?= number_format($row['bet_amount'], 2) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <span><?= number_format($row['lottery'], 2) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($row['win_lose'] < 0) { ?>
                                        <span style='color:red'><?= number_format($row['win_lose'], 2) ?></span>
                                        <?php } else { ?>
                                        <span style='color:blue'><?= number_format($row['win_lose'], 2) ?></span>
                                        <?php } ?>
                                    </div>
                                </td>
                                <!-- <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['service_fee'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['settlement_time'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= ($row['bet_status'] == 1 ? $lang['settlement'] : $lang['unsettlement']) ?></span>
                                    </div>
                                </td> -->
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['game_no'] ?></span>
                                    </div>
                                </td>
                                <!-- <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['remark'] ?></span>
                                    </div>
                                </td> -->
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
                if (count($record['data']) > 0) {
                ?>
                <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                    <?= $lang['total'] ?> <?= $record['pagination']['total'] ?> <?= $lang['count'] ?>
                </div>
                <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                    <?= $page->createPager($record['pagination']['pages'], $record['pagination']['current'], $lang) ?>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
<script>
$(document).ready(function() {

    //換頁
    $(document).on('click', '.paginate_button', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var page = $(this).attr('data-dt-idx');
        var is_disable = $(this).hasClass('disabled');
        if (!is_disable) {
            var result = $(location).attr('search').split('&page')[0]
            if (result == '') {
                window.location = $(location).attr('href') + '?page=' + page;
            } else {
                window.location = result + '&page=' + page;
            }
        }
    });

});
</script>