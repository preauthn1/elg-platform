<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['event/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// 活動列表
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
$data['status'] = isset($_GET['status'])? $_GET['status'] : 1;
$data['title'] = isset($_GET['title'])? $_GET['title'] : "";

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
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['event_exclusive_offers'] ?> > <?= $lang['event_management'] ?></h4>
            </div>
            <div class="row">
                <a href="new_edit.php" class="btn btn-inverse btn-success">
                    <?= $lang['event_create'] ?>
                </a>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <label for="form-1-1" class="col-md-1 control-label">
                                        <?= $lang['event_name'] ?>
                                    </label>
                                    <div class="col-md-2">
                                        <input type="text" class="form-control" name="title"
                                            value="<?= $_GET['title'] ?>" />
                                    </div>
                                    <label for="form-1-1" class="col-md-1 control-label">
                                        <?= $lang['status'] ?>
                                    </label>
                                    <div class="col-md-2">
                                        <select id="selectize-dropdown" name="status">
                                            <option value=""><?= $lang['all'] ?></option>
                                            <option value="1" <?= 'selected' ?>>
                                                <?= $lang['enable'] ?></option>
                                            <option value="0" <?= ($_GET['status'] == '0') ? 'selected' : '' ?>>
                                                <?= $lang['disable'] ?></option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                        <input type="reset" class="btn btn-default clear"
                                            value="<?= $lang['clear'] ?>" />
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
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
                                            <th><?= $lang['label_start_time'] ?></th>
                                            <th><?= $lang['label_end_time'] ?></th>
                                            <th><?= $lang['event_reward'] ?></th>
                                            <th><?= $lang['available_multiple_get'] ?></th>
                                            <th><?= $lang['event_deadline'] ?></th>
                                            <th><?= $lang['status'] ?></th>
                                            <th><?= $lang['features'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($events as $event) { ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-success">
                                                        <b>
                                                            <?= $event['agent_username'] ?>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $event['title'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                    if (count($event['detail']) > 0) {
                                                        foreach ($event['detail'] as $detail) {
                                                    ?>
                                                <div class="list-info mrg-top-10">
                                                    <span class="pdd-left-20">
                                                        <?= $lang['event_action.' . $actions[$detail['action_id']]] . ' over ' . $detail['point'] ?>
                                                    </span>
                                                </div>
                                                <?php
                                                        }
                                                    }
                                                    ?>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b>
                                                            <?= $detail['start_time'] ?>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b>
                                                            <?= $detail['end_time'] ?>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="text-info">
                                                        <b>
                                                            <?= $prize_types[$event['prize_type_id']] ?>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15  text-center">
                                                    <span class="text-info">
                                                        <?php if ($event['prize_is_repeatedly'] == 1) { ?>
                                                            <i class="fa fa-check text-success"></i>
                                                        <?php } elseif ($event['prize_is_repeatedly'] == 2) { ?>
                                                            <i class="fa fa-clock-o text-info"></i>
                                                        <?php } else { ?>
                                                            <i class="fa fa-times text-danger"></i>
                                                        <?php } ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b>
                                                            <?= $event['prize_expire_date'] ?>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="<?=($event['status'] == 1) ? 'text-success' : 'text-danger'; ?>">
                                                        <b>
                                                            <?= ($event['status'] == 1) ? $lang['enable'] : $lang['disable']; ?>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button class="btn btn-default btn-sm dropdown-toggle"
                                                            type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <?php if ($_SESSION['host_id'] == $event['agent_id'] && $event['status'] == 1) { ?>
                                                            <li>
                                                                <a href="new_edit.php?id=<?=$event['id']?>">
                                                                    <?=$lang['edit']?>
                                                                </a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <?php } ?>
                                                            <li>
                                                                <a href="#" class="event_toggle_status"
                                                                    id="<?= $event['id'] ?>"
                                                                    status="<?= ($event['status'] != 1) ? 1 : 0 ?>">
                                                                    <?= ($event['status'] != 1) ? $lang['enable'] : $lang['disable'] ?>
                                                                </a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
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
    <?php require_once '../_bottom.php'; ?>
</div>
<?php require_once '../_footer.php'; ?>
<script>
$(document).ready(function() {
    $('#date-range-picker').daterangepicker({
        startDate: '<?= $start_date ?>',
        endDate: '<?= $end_date ?>',
        locale: {
            format: 'YYYY-MM-DD'
        }
    });

    //修改狀態
    $(document).on('click', '.event_toggle_status', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var id = $(this).attr('id');
        var status = $(this).attr('status');
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        })
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'event_toggle_status',
            'id': id,
            'status': status
        }, function(data) {
            $.unblockUI();
            if (data.code == '1000') {
                window.location.reload();
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    });
});
</script>