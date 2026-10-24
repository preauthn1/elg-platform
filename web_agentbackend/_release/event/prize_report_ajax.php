<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$data = array();
$data['event_id'] = $_GET['event_id'] ? $_GET['event_id'] : 0;
if ($_GET['type'] == 1 && $_GET['username'] != '') {
    $data['user_username'] = $_GET['username'];
} else if ($_GET['type'] == 2 && $_GET['username'] != '') {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 3 && $_GET['username'] != '') {
    $data['member_username'] = $_GET['username'];
}
$data['page'] = $_GET['page'] ? $_GET['page'] : 1;
$data['per_page'] = $_GET['per_page'] ? $_GET['per_page'] : 50;
$record = curlPost('api/report/prizeReport', $data);
if ($record['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <button type="button" class="btn btn-success" id="excel" style="float:right"
                data-username="<?= $_GET['username'] ?>" data-type="<?= $_GET['type'] ?>">Excel</button>
            <div class="table-overflow">
                <table class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['exchange_time'] ?></th>
                            <th><?= $lang['event_name'] ?></th>
                            <th><?= $lang['member_account'] ?></th>
                            <th><?= $lang['agent_previous'] ?></th>
                            <th><?= $lang['reward_name'] ?></th>
                            <th><?= $lang['point'] ?></th>
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
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['updated_at'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['event_title'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span class="text-info"><b><?= $row['member_username'] ?></b></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['agent_username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['prize_name'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['prize_point'] ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php
                                }
                            } else { ?>
                        <tr>
                            <td colspan="6" align='center'>
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