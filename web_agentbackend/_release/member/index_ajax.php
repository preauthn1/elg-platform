<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

$is_flow_limit_permission = check_permission_menuCode('member_list_flow_limit');
$is_ip_inquire_permission = check_permission_menuCode('ip_inquire_permission');
$is_show_proxy_domain = check_permission_menuCode('show_proxy_domain');
$is_show_toggle_status = check_permission_menuCode('member_disabled_permissiom');

if ($_GET['act'] == 1) {
    $data = array();
    // agent_id
    if ($_GET['agent_username'] != '' && isset($_GET['agent_username'])) {
        $data['agent_username'] = $_GET['agent_username'];
    }
    // username
    if ($_GET['username'] != '' && isset($_GET['username'])) {
        $data['username'] = $_GET['username'];
    }
    // nickname
    if ($_GET['nickname'] != '' && isset($_GET['nickname'])) {
        $data['nickname'] = $_GET['nickname'];
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
    // username
    if ($_GET['mobile'] != '' && isset($_GET['mobile'])) {
        $data['mobile'] = $_GET['mobile'];
    }
    if ($_GET['mobile_authentication'] != '' && isset($_GET['mobile_authentication'])) {
        $data['mobile_authentication'] = $_GET['mobile_authentication'];
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
}

$page = new CreatePage();
?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <input type="hidden" id="flow_amount_member_id">
            <input type="hidden" id="ip_member_id">
            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
            <div class="table-overflow">
                <table id="dt-opt" class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['createdate'] ?></th>
                            <th><?= $lang['agent_account'] ?></th>
                            <th><?= $lang['username'] ?> </th>
                            <th><?= $lang['nickname'] ?> </th>
                            <th><?= $lang['real_name'] ?> </th>
                            <th><?= $lang['balance'] ?></th>
                            <?php if ($_SESSION['has_transaction']) { ?>
                            <th><?= $lang['transfer'] ?></th>
                            <?php } ?>
                            <th><?= $lang['tel'] ?></th>
                            <th><?= $lang['mobile_status_certification'] ?></th>
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
                                    <span class="pdd-left-20">
                                        <?= $row['created_at'] ?>
                                    </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                    <span class="text-success">
                                        <b>
                                            <span class="pdd-left-20">
                                                <?= $row['agent_username'] ?>
                                            </span>
                                        </b>
                                    </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15">
                                    <span class="pdd-left-20">
                                        <?= $row['username'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="pdd-left-20">
                                        <?= $row['nickname'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="pdd-left-20">
                                        <?= $row['first_name'] ?>
                                    </span>
                                    </div>
                                </td>
                                <!--
                                毫無意義的欄位 -->
                                <!-- <td>
                                <div class="list-info mrg-top-10">
                                    <img class="thumb-img"
                                        src="<?= ($row['image'] != '') ? $row['image'] : '../../assets/images/avatars/thumb-4.jpg' ?>"
                                        alt="">
                                    <div class="info">
                                    </div>
                                </div>
                            </td> -->
                            <td>
                                <div class="relative mrg-top-15">
                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                        amount_thousands_off($row['balance'], 4);
                                    } else {
                                        amount_thousands_on($row['balance'], 4);
                                    }?>
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
                                    <span>
                                        <?= $row['mobile'] ?>
                                    </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 <?= ($row['mobile_validate'] == 1) ? '' : 'text-warning' ?>">
                                        <?= ($row['mobile_validate'] == 1) ? $lang['pass'] : $lang['not_certified'] ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 <?= ($row['status'] == 1) ? '' : 'text-danger' ?>">
                                        <?= ($row['status'] == 1 ? $lang['enable'] : ($row['status'] == 2 ? $lang['user_block'] : $lang['disable'])) ?>
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
                                                <?php if ($is_flow_limit_permission) { ?>
                                                    <li>
                                                        <a href="#" data-toggle="modal" data-target="#flow_limit"
                                                           data-uid="<?= $row['id'] ?>"
                                                           class="flow_limit"><?= $lang['flow_limit'] ?>
                                                        </a>
                                                    </li>
                                                    <?php
                                                }
                                                if ($is_show_toggle_status) {
                                                    ?>
                                                    <li class="divider"></li>
                                                    <li>
                                                        <a href="#" class="member_toggle_status" id="<?= $row['id'] ?>"
                                                           status="<?= ($row['status'] != 1) ? 1 : 0 ?>">
                                                            <?= ($row['status'] != 1) ? $lang['enable'] : $lang['disable'] ?>
                                                        </a>
                                                    </li>
                                                    <?php
                                                }
                                                if ($is_show_proxy_domain) {
                                                    ?>
                                                    <li>
                                                        <a href="#" data-toggle="modal" data-target="#domain"
                                                           data-aid="<?= $row['parent'] ?>"
                                                           class="domain"><?= $lang['domain'] ?>
                                                        </a>
                                                    </li>
                                                    <?php
                                                }
                                                if ($is_ip_inquire_permission) {?>
                                                <li>
                                                    <a href="#" data-toggle="modal" data-target="#ip"
                                                       data-aid="<?= $row['id'] ?>"
                                                       class="ip">IP
                                                    </a>
                                                </li>
                                                <?php } ?>
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
                                    <span class="text-info">
                                        <b>
                                            <?= $lang['no_record'] ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php }
                    ?>
                    </tbody>
                </table>
            </div>
            <div id="member_search_paging">
                <?php if (count($record['data']) > 0) { ?>
                    <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                        <?= $lang['total'] ?> <?= $record['pagination']['total'] ?> <?= $lang['count'] ?>
                    </div>
                    <div class="dataTables_paginate pagiimple_numbers" id="dt-opt_paginate">
                        <?= $page->createPager($record['pagination']['pages'], $record['pagination']['current'], $lang) ?>
                    </div>
                <?php } ?>

            </div>
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
    $(document).ready(function () {

        //換頁
        $(document).on('click', '#member_search_paging .paginate_button', function (e) {
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
        $(document).on('click', '.transaction', function (e) {
            //先清內容
            $("#transaction_content").html('');
            var id = $(this).attr('data-uid');
            var type = $(this).attr('data-type');
            $.post("doTransaction.php", {
                'id': id,
                'type': type,
            }, function (data) {
                $("#transaction_content").html(data);
            });
        });

        //開啟稽核流水
        $(document).on('click', '.flow_limit', function (e) {
            //先清內容
            $("#flow_limit_content").html('');

            let flow_member_id = $(this).attr('data-uid');
            let page = 1;

            //畫面中有藏一個hidden input來儲存member_id
            $("#flow_amount_member_id").val(flow_member_id);
            load_flow_limit(page);
        });

        $(document).on('click', '.domain', function (e) {
            $("#domain_content").html('');
            $.blockUI();
            $.post("<?= BASE_URL ?>member/domain.php", {
                'agent_id': $(this).attr('data-aid')
            }, function (data) {
                $("#domain_content").html(data);
                $.unblockUI();
            });
        });

        $(document).on('click', '.ip', function (e) {
            $("#ip_content").html('');
            let ip_member_id = $(this).attr('data-aid');
            let page = 1;
            $.blockUI();
            $("#ip_member_id").val(ip_member_id);
            load_ip(page);
        });
    });
</script>