<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
if (!isset($check_auth['event/index'])) {
    echo $lang['nopermission'];
    exit;
}
// 活動列表
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
$result = curlPost('api/event/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . BASE_URL . "index.php");
    }
    exit();
} else {
    $events = $result['data'];
}
// 獎勵遊戲類型
$data = array();
$result = curlPost('api/prizeType/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . BASE_URL . "index.php");
    }
    exit();
} else {
    $prize_types = array();
    foreach ($result['data'] as $row) {
        $prize_types[$row['id']] = $row['name'];
    }
}
// 條件類型
$data = array();
$data['key'] = 'event_action';
$result = curlPost('api/param/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . BASE_URL . "index.php");
    }
    exit();
} else {
    $actions = array();
    foreach ($result['data'] as $row) {
        $actions[$row['value']] = $row['parameter'];
    }
}
?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['event_exclusive_offers'] ?> > <?= $lang['event_management'] ?></h4>
            </div>
            <div class="row">
                <a href="edit.php" class="btn btn-inverse btn-success"><?= $lang['event_create'] ?></a>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['agent_name'] ?></th>
                                            <th><?= $lang['event_name'] ?></th>
                                            <th><?= $lang['condition_satisfy'] ?></th>
                                            <th><?= $lang['event_reward'] ?></th>
                                            <th><?= $lang['available_multiple_get'] ?></th>
                                            <th><?= $lang['event_deadline'] ?></th>
                                            <!-- <th></th> -->
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($events as $event) {
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-success"><b>
                                                            <span><?= $event['agent_username'] ?></span>
                                                        </b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $event['title'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                    if (count($event['detail']) > 0) {
                                                        foreach ($event['detail'] as $detail) {
                                                    ?>
                                                <div class="list-info mrg-top-10">
                                                    <span
                                                        class="pdd-left-20"><?= $lang['event_action.' . $actions[$detail['action_id']]] . ' over ' . $detail['point'] . ' betweent ' . $detail['start_time'] . ' and ' . $detail['end_time'] ?></span>
                                                </div>
                                                <?php
                                                        }
                                                    }
                                                    ?>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span
                                                        class="text-info"><b><?= $prize_types[$event['prize_type_id']] ?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span
                                                        class="text-info"><b><?= ($event['prize_is_repeatedly'] == 1) ? $lang['available_multiple_can'] : $lang['available_not_multiple'] ?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $event['expire_date'] ?></b></span>
                                                </div>
                                            </td>
                                            <!-- <td>
                                                <?php if ($_SESSION['id'] == $event['agent_id']) { ?>
                                                <a href="edit.php?id=<?= $event['id'] ?>"
                                                    class="btn btn-default btn-sm"><?= $lang['edit'] ?></a>
                                                <?php } ?>
                                            </td> -->
                                        </tr>
                                        <?php
                                        }
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
    <!-- Content Wrapper END -->
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<!-- Page Container END -->
<?php
require_once '../_footer.php';
?>
<script>
$(document).ready(function() {
    $('#date-range-picker').daterangepicker({
        startDate: '<?= $start_date ?>',
        endDate: '<?= $end_date ?>',
        locale: {
            format: 'YYYY-MM-DD'
        }
    });
});
</script>