<?php
require_once '../_init.php';

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
                            <th>兌換時間</th>
                            <th>活動名稱</th>
                            <th>會員帳號</th>
                            <th>上線代理</th>
                            <th>獎項名稱</th>
                            <th>點數</th>
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
                                    <span class="text-info"><b>暫無記錄</b></span>
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