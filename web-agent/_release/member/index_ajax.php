<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
$data = array();
// agent_id
if ($_GET['agent_id'] != '' && isset($_GET['agent_id'])) {
    $data['agent_id'] = $_GET['agent_id'];
}
// username
if ($_GET['username'] != '' && isset($_GET['username'])) {
    $data['username'] = $_GET['username'];
}
// email
if ($_GET['email'] != '' && isset($_GET['email'])) {
    $data['email'] = $_GET['email'];
}
// 狀態
if ($_GET['status'] != '' && isset($_GET['status'])) {
    $data['status'] = $_GET['status'];
}
// 認證狀態
if ($_GET['certification'] != '' && isset($_GET['certification'])) {
    $data['certification'] = $_GET['certification'];
}
// 查看下線
if (isset($_GET['id'])) {
    $data['id'] = $_GET['id'];
}
$data['page'] = $_GET['page'] ? $_GET['page'] : 1;
$data['per_page'] = 50;
$record = curlPost('api/member/list', $data);
if ($record['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
$page = new CreatePage();

?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
        <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
            <div class="table-overflow">
                <table id="dt-opt" class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['createdate'] ?> </th>
                            <th><?= $lang['agent_account'] ?> </th>
                            <th><?= $lang['username'] ?> </th>
                            <th><?= $lang['name'] ?></th>                            
                            <th><?= $lang['balance'] ?></th>

                            <?php if ($_SESSION['has_transaction']) { ?>
                            <th><?= $lang['transfer'] ?></th> <?php } ?>

                            <th><?= $lang['email'] ?></th>
                            <th><?= $lang['tel'] ?></th>
                            <th><?= $lang['status_certification'] ?></th>
                            <th><?= $lang['status_member'] ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (count($record['data']) > 0) {
                            foreach ($record['data'] as $row) {
                        ?>
                        <tr>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="pdd-left-20"><?= $row['created_at'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="text-success"><b>
                                            <span class="pdd-left-20"><?= $row['agent_username'] ?></span>
                                        </b></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <!-- <span class="status away"> </span> -->
                                    <span class="pdd-left-20"><?= $row['username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="list-info mrg-top-10">
                                    <img class="thumb-img" src="assets/images/avatars/thumb-4.jpg" alt="">
                                    <div class="info">
                                        <span class="title"><?= $row['first_name'] . ' ' . $row['last_name'] ?></span>
                                        <span class="sub-title"><?= $lang['id'] ?> <?= $row['id'] ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span><?= $row['balance'] ?></span>
                                </div>
                            </td>

                            <?php if ($_SESSION['has_transaction']) { ?>
                            <td>
                                <div class="relative mrg-top-15">
                                    <a href="#" data-toggle="modal" data-target="#transaction" class="transaction"
                                        data-uid="<?= $row['id'] ?>" data-type="1">
                                        <?= $lang['deposit'] ?>
                                    </a>　
                                    <a href="#" data-toggle="modal" data-target="#transaction" class="transaction"
                                        data-uid="<?= $row['id'] ?>" data-type="2">
                                        <?= $lang['withdrawal'] ?>
                                    </a>
                                </div>
                            </td>
                            <?php } ?>

                            <td>
                                <div class="mrg-top-15">
                                    <span class="text-info"><b><?= $row['email'] ?></b></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['mobile'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 <?= ($row['is_validate'] == 1) ? '' : 'text-warning' ?>">
                                    <?= ($row['is_validate'] == 1) ? $lang['pass'] : $lang['not_certified'] ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 <?= ($row['status'] == 1) ? '' : 'text-danger' ?>">
                                    <?= ($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-10 text-center">
                                    <div class="dropdown inline-block">
                                        <button class="btn btn-default btn-sm dropdown-toggle" type="button"
                                            data-toggle="dropdown">
                                            <i class="ti-angle-down font-size-9"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a
                                                    href="profile.php?id=<?= $row['id'] ?>"><?= $lang['detailinfo'] ?></a>
                                            </li>
                                            <li>
                                                <a href="#" data-toggle="modal" data-target="#flow_limit"
                                                    data-uid="<?= $row['id'] ?>"
                                                    class="flow_limit"><?= $lang['flow_limit'] ?>
                                                </a>
                                            </li>
                                            <li class="divider"></li>
                                            <li>
                                                <a href="#" class="member_toggle_status" id="<?= $row['id'] ?>"
                                                    status="<?= ($row['status'] != 1) ? 1 : 0 ?>"><?= ($row['status'] != 1) ? $lang['enable'] : $lang['disable'] ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php
                            }
                            ?>
                        <?php
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
<?php if ($_SESSION['has_transaction']) { ?>
<div class="modal fade" id="transaction">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="transaction_content">
        </div>
    </div>
</div>
<?php } ?>
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

    //開啟轉帳視窗
    $(document).on('click', '.transaction', function(e) {
    	//先清內容
        $("#transaction_content").html('');
        var id = $(this).attr('data-uid');
        var type = $(this).attr('data-type');
        $.post("doTransaction.php", {
            'id': id,
            'type': type,
        }, function(data) {
            $("#transaction_content").html(data);
        });
    });

    //開啟稽核流水
    $(document).on('click', '.flow_limit', function(e) {
    	//先清內容
        $("#flow_limit_content").html('');
        var id = $(this).attr('data-uid');
        $.post("flow_limit.php", {
            'member_id': id
        }, function(data) {
            $("#flow_limit_content").html(data);
        });
    });
});
</script>