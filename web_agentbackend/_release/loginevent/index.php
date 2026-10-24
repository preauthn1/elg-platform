<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

/*
if (!isset($check_auth['event/login_index'])) {
    echo $lang['nopermission'];
    exit;
}
*/

$page = new CreatePage();

// 活動列表
$filter = array();
$filter['page'] = 1;
$filter['per_page'] = 1000;
$filter['title'] = $_GET['title'];
$filter['status'] = ($_GET['status'] == '') ? 'all' : $_GET['status'];
$result = curlPost('api/loginEvent/list', $filter);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . BASE_URL . "login_index.php");
    }
    exit();
} else {
    $events = $result['data'];
}

// 遊戲商列表
$result = curlPost('api/vendor/list');
$vendors = array();
foreach ($result['data'] as $row) {
    $vendors[$row['id']] = $row['name'];
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
                <h4><?= $lang['event_exclusive_offers'] ?> > <?= $lang['event_login_management'] ?></h4>
            </div>
            <div class="row">
                <a href="edit.php" class="btn btn-inverse btn-success"><?= $lang['event_create'] ?></a>
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
                                            <option value="all" selected><?= $lang['all'] ?></option>
                                            <option value="1" <?= ($_GET['status'] == '1') ? 'selected' : '' ?>>
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
                                <table id="dt-opt-login-event" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['event_name'] ?></th>
                                            <th><?= $lang['label_start_time'] ?></th>
                                            <th><?= $lang['label_end_time'] ?></th>
                                            <th><?= $lang['agent_name'] ?></th>
                                            <th><?= $lang['login_event_type'] ?></th>
                                            <th><?= $lang['not_withdraw_record'] ?></th>
                                            <th><?= $lang['trial_amount'] ?></th>
                                            <th><?= $lang['unlock_amount'] ?></th>
                                            <th><?= $lang['trial_vendor'] ?></th>
                                            <th><?= $lang['repeat_participation'] ?></th>
                                            <th><?= $lang['number_of_participants'] ?>/<?= $lang['the_number_of_participants'] ?></th>
                                            <th><?= $lang['status'] ?></th>
                                            <th><?= $lang['features'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($events as $event) { ?>
                                        <tr>
                                            <td class="text_left">
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title">
                                                            <?= $event['title'] ?>
                                                        </span>
                                                        <span class="sub-title">
                                                            <?= $lang['id'] . $event['id'] ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b>
                                                            <span><?= $event['start_time'] ?></span>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b>
                                                            <span><?= $event['end_time'] ?></span>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-success"><b>
                                                            <span><?= $event['agent_username'] ?></span>
                                                        </b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <?php /*1:活動開始後創立之新帳號、2:舊帳號, 且幾日內無開洗分紀錄 */ ?>
                                                    <span><?= $lang['login_event_type.' . $event['type']] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $event['how_days'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-right">
                                                        <?= $event['point'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php /*解鎖條件：達到贈送點數的幾倍*/ ?>
                                                <div class="mrg-top-15">
                                                    <span class="text-right">
                                                        <?= $event['times'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15  text-center">
                                                    <?php
                                                    $tag = '';
                                                    $vendor_ids = explode(',', $event['vendor_id']);
                                                    if (count($vendor_ids) > 0) {
                                                        foreach ($vendor_ids as $vendor_id) {
                                                            if (isset($vendors[$vendor_id])) {
                                                                echo $tag . $vendors[$vendor_id];
                                                                $tag = ', ';
                                                            }
                                                        }
                                                    }
                                                    ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-center">
                                                    <span class="<?= ($event['repeat_hour'] == 1) ? 'fa fa-check text-success' : 'fa fa-times text-danger' ?>">
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 align-middle">
                                                    <b>
                                                        <?php if ($event['number_of_people'] == 0) { ?>
                                                            <span class="text-success"><?= $lang['unlimited'] ?></span>
                                                        <?php } else { ?>
                                                            <span class="<?= ($event['number_of_participants'] >= $event['number_of_people']) ? 'text-danger' : 'text-success' ?>">
                                                                <?= $event['number_of_people'] ?> / <?= $event['number_of_participants'] ?>
                                                            </span>
                                                        <?php } ?>
                                                    </b>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-center">
                                                    <span class="<?= ($event['status'] == 1) ? 'text-success' : 'text-danger' ?>">
                                                        <?= ($event['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
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
                                                                    <a href="edit.php?id=<?=$event['id']?>">
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
                                            </td>
                                        </tr>
                                        <?php
                                        }
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
            				<?php 
            				}
            				?>
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
        'action': 'login_event_toggle_status',
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

$('#dt-opt-login-event').DataTable({
    "order": [
        [11, "desc"],
        [0, "desc"]
    ]
});
</script>