<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
$data = array();
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
// 查看下線
if (isset($_GET['id'])) {
    $data['id'] = $_GET['id'];
}
$data['page'] = $_GET['page'] ? $_GET['page'] : 1;
$data['per_page'] = 50;
$record = curlPost('api/bank/list', $data);
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
                <table id="dt-opt2" class="table table-lg table-hover">
                    <thead>
                    <tr>
                        <th><?= $lang['createdate'] ?></th>
                        <th><?= $lang['sharecode'] ?></th>
                        <th><?= $lang['deposit'] ?></th>
                        <th><?= $lang['status'] ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($record['data'] as $row) {
                        if ($row['id'] != $_SESSION['id']) {
                            ?>
                            <tr>
                                <td>
                                    <div class="relative mrg-top-15">
                                        <span class="pdd-left-20"><?= $row['bank_name'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                        <span class="pdd-left-20"><?= $row['bank_code'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                        <span class="pdd-left-20"><?= $row['bank_account'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 <?= ($row['status'] != 1) ? 'text-danger' : '' ?>">
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
                                                        href="edit.php?id=<?= $row['id'] ?>"><?= $lang['edit'] ?></a>
                                                </li>
                                                <li>
                                                    <a href="#" class="agent_toggle_status" id="<?= $row['id'] ?>"
                                                       status="<?= ($row['status'] != 1) ? 1 : 0 ?>"><?= ($row['status'] != 1) ? $lang['enable'] : $lang['disable'] ?></a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
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
            <?php } ?>
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