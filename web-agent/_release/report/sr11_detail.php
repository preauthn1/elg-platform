<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$data = array();
$data['vendor_id'] = $_GET['vendor_id'] ? $_GET['vendor_id'] : 0;
$data['game_type'] = $_GET['game_type'] ? $_GET['game_type'] : 0;
$data['start_time'] = ($_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d 00:00', strtotime('-1day'))) . ':00';
$data['end_time'] = ($_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d 00:00')) . ':00';
if ($_GET['type'] == 1 && $_GET['username'] != '') {
    $data['user_username'] = $_GET['username'];
} else if ($_GET['type'] == 2 && $_GET['username'] != '') {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 3 && $_GET['username'] != '') {
    $data['member_username'] = $_GET['username'];
}
$data['page'] = $_GET['page'] ? $_GET['page'] : 1;
$data['per_page'] = 50;
$record = curlPost('api/report/platformReconciliationDetail', $data);
// var_dump($record);
// exit;
if ($record['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <button type="button" class="btn btn-success" id="excel_detail" style="float:right"
                data-username="<?= $_GET['username'] ?>" data-type="<?= $_GET['type'] ?>">Excel</button>
            <div class="table-overflow">
                <table class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['nextlevel_detail'] ?></th>
                            <th><?= $lang['num'] ?></th>
                            <th><?= $lang['agent_account'] ?></th>
                            <th><?= $lang['agent_name'] ?></th>
                            <th><?= $lang['platform'] ?></th>
                            <th><?= $lang['game_type'] ?></th>
                            <th class="text-right"><?= $lang['total_bet'] ?></th>
                            <th class="text-right"><?= $lang['total_winlose'] ?></th>
                            <th class="text-right"><?= $lang['jackpot_event'] ?></th>
                            <th class="text-right"><?= $lang['total_score'] ?></th>
                            <th class="text-right"><?= $lang['divided'] ?></th>
                            <th><?= $lang['divided_between'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (count($record['data']) > 0) {
                            $i = 0;
                            foreach ($record['data'] as $row) {
                                // $total_bet += $row['total_bet'];
                                // $win_lose += $row['win_lose'];
                                // $lottery += $row['lottery'];
                                // $share_amount += $row['share_amount'];
                                $i++;
                        ?>
                        <tr>
                            <td>
                                <div class=" mrg-top-15">
                                    <a class="<?= ($row['type'] == 'agent') ? 'agent_bottom' : 'member_bottom' ?>"
                                        style="color:blue; cursor: pointer;"
                                        data-username="<?= $row['agent_username'] ?>">
                                        <?= $lang[$row['type']] ?>
                                    </a>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $i  ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['agent_username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['agent_nickname'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span class="text-info"><b><?= $row['vendor'] ?></b></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['game_type'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <span><?= number_format($row['total_bet'], 2) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <span
                                        <?= ($row['win_lose'] < 0) ? 'style="color:red"' : '' ?>><?= number_format($row['win_lose'], 2) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <span><?= number_format($row['lottery'], 2) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php if (($row['win_lose'] + $row['lottery']) < 0) { ?>
                                    <span
                                        style='color:red'><?= number_format(($row['win_lose'] + $row['lottery']), 2) ?></span>
                                    <?php } else { ?>
                                    <span><?= number_format(($row['win_lose'] + $row['lottery']), 2) ?></span>
                                    <?php } ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php if ($row['share_amount'] < 0) { ?>
                                    <span style='color:red'><?= number_format($row['share_amount'], 2) ?></span>
                                    <?php } else { ?>
                                    <span><?= number_format($row['share_amount'], 2) ?></span>
                                    <?php } ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>-</span>
                                </div>
                            </td>
                        </tr>
                        <!-- 放下一層的內容 -->
                        <tr>
                            <td class="panel agent<?= $row['agent_username'] . $row['vendor_id'] . $row['game_type_id'] ?>"
                                colspan="12" style="display: none;"></td>
                        </tr>
                        <tr>
                            <td class="panel member<?= $row['agent_username'] . $row['vendor_id'] . $row['game_type_id']  ?>"
                                colspan="12" style="display: none;"></td>
                        </tr>
                        <?php } ?>
                        <?php  } else { ?>
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
                $page = new CreatePage();
            ?>
            <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite"
                data-username="<?= $_GET['username'] ?>">
                <?= $lang['total'] ?> <?= $record['pagination']['total'] ?> <?= $lang['count'] ?>
            </div>
            <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                <?= $page->createPager($record['pagination']['pages'], $record['pagination']['current'], $lang) ?>
            </div>
            <?php } ?>
        </div>
    </div>
</div>