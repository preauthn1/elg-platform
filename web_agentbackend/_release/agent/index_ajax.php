<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$data = array();
parse_str($_SERVER['QUERY_STRING'], $data);

$data['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$data['per_page'] = 50;

$record = curlPost('api/agent/list', $data);

if ($record['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
}

$isAdvnceSettingPermission = check_permission_menuCode('agent_advance_setting');
$isUpdateAgentGroupPermission = check_permission_menuCode('update_agent_group_permissions');

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
                            <th><?= $lang['agent_previous'] ?></th>
                            <th><?= $lang['username'] . $lang['sub_account_tip'] ?></th>
                            <th><?= $lang['name'] ?></th>
                            <?php if ($_SESSION['has_transaction']) { ?>
                            <th><?= $lang['balance'] ?></th>
                            <th><?= $lang['transfer'] ?></th> <?php } ?>

                            <th>
                                <?= $lang['email'] ?>／<?= $lang['tel'] ?>
                            </th>

                            <th><?= $lang['status'] ?></th>
                            <th style="width:8%;">
                                <?= $lang['last_operator'] ?>／<?= $lang['created_operator'] ?>
                            </th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($record['data'] as $row) {       ?>

                        <?php 
                            $is_self_account =  $row['id'] == $_SESSION['id'];
                        ?>

                        <tr>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="pdd-left-20"><?= $row['created_at'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="pdd-left-20"><?= $row['code'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="pdd-left-20 text-info"><b><?= $row['parent_username'] ?></b></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="pdd-left-20"
                                        <?= ($row['host_id'] > 0 ? 'style="color:#008800"' : '') ?>><?= ($row['host_id'] > 0 ? '* ' : '') . $row['username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="pdd-left-20"><?= $row['nickname'] ?></span>
                                </div>
                            </td>

                            <?php if ($_SESSION['has_transaction']) { ?>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span><?= ($row['balance'] != -1) ? $row['balance'] : '無限制' ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <?php if ($row['balance'] != -1 && !$is_self_account) { ?>

                                    <div>
                                        <a href="#" data-toggle="modal" data-target="#transaction" class="transaction"
                                            data-uid="<?= $row['id'] ?>" data-type="1">
                                            <?= $lang['deposit'] ?>
                                        </a>　
                                    </div>

                                    <div>
                                        <a href="#" data-toggle="modal" data-target="#transaction" class="transaction"
                                            data-uid="<?= $row['id'] ?>" data-type="2">
                                            <?= $lang['withdrawal'] ?>
                                        </a>
                                    </div>

                                    <?php } else { ?>
                                    -
                                    <?php } ?>
                                </div>
                            </td>
                            <?php } ?>

                            <td>
                                <div class="mrg-top-15">

                                    <div>
                                        <span class="text-info"><b><?= $row['email'] ?></b></span>
                                    </div>

                                    <div>
                                        <span><?= $row['mobile'] ?></span>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div class="mrg-top-15 <?= ($row['status'] != 1) ? 'text-danger' : '' ?>">
                                    <?= ($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <div title=" <?= $lang['last_operator'] ?>">
                                        <span><?= $row['operator'] ?></span>
                                    </div>
                                    <div title=" <?= $lang['created_operator'] ?>">
                                        <span><?= $row['created_by'] ?></span>
                                    </div>

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
                                                <a href="profile.php?id=<?= $row['id'] ?>">
                                                    <?= $lang['detailinfo'] ?>
                                                </a>
                                            </li>
                                            <li>
                                                <a href="index.php?id=<?= $row['id'] ?>">
                                                    <?= $lang['check_lower'] ?>
                                                </a>
                                            </li>
                                            <li>
                                                <a href="../vendor/index.php?id=<?= $row['id'] ?>">
                                                    <?= $lang['menu_vendor'] ?>
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" data-toggle="modal" data-target="#domain_list"
                                                    data-uid="<?= $row['id'] ?>" class="domain">
                                                    <?= $lang['domain_noted_check'] ?>
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" data-code="<?= $row['code'] ?>" class="copy">
                                                    <?= $lang['copy_promote_url'] ?>
                                                </a>
                                            </li>

                                            <!--有權限或這個帳號不是Admin的子帳號才顯示 -->
                                            <?php if ($isUpdateAgentGroupPermission  && $row['host_id'] != 1) { ?>

                                                <li>
                                                    <a href="/agent/agent_group_permission.php?agent_id=<?= $row['id']?>">
                                                        <?= $lang['menu_update_agent_group_permissions'] ?>
                                                    </a>
                                                </li>

                                            <?php } ?>

                                            <!--有權限才顯示 -->
                                            <?php if ($isAdvnceSettingPermission && $row['host_id'] == 0) { ?>

                                            <li>
                                                <a href="/agent/advance_setting.php?agent_id=<?= $row['id']?>">
                                                    <?= $lang['menu_agent_advance_setting'] ?>
                                                </a>
                                            </li>

                                            <?php } ?>

                                            <li>
                                                <?php if (!$is_self_account) { ?>

                                                <a href="#" class="agent_toggle_status" id="<?= $row['id'] ?>"
                                                    status="<?= ($row['status'] != 1) ? 1 : 0 ?>"><?= ($row['status'] != 1) ? $lang['enable'] : $lang['disable'] ?>
                                                </a>

                                                <?php } ?>
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
            } ?>
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