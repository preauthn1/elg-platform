<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$filter = array();
$filter['id'] = $_POST['member_id'];
$filter['page'] = isset($_POST['page']) ? $_POST['page'] : 1;
$filter['per_page'] = 10;
$result = curlPost('api/member/getLoginLog', $filter);

$page = new CreatePage();
?>
<div class="modal-body">
    <a class="modal-close" href="" data-dismiss="modal" style="float: right;">
        <i class="ti-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title"><?= $lang['menu_ip_inquire_permission'] ?></h4>
                                <div class="table-overflow">
                                    <input type="hidden" id="ip_member_id" value="<?= $_POST['member_id'] ?>">
                                    <table class="table table-striped">
                                        <thead>
                                        <tr>
                                            <th class="text-center"><?= $lang['date'] ?></th>
                                            <th class="text-center"><?= $lang['ip'] ?></th>
                                            <th class="text-center"><?= $lang['status'] ?></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        if (count($result['data']) > 0 && $result['code'] == 1000) {
                                            foreach ($result['data'] as $row) { ?>
                                                <tr>
                                                    <td>
                                                    <span>
                                                        <?= $row['created_at'] ?>
                                                    </span>
                                                    </td>
                                                    <td>
                                                    <span>
                                                        <?= $row['ip'] ?>
                                                    </span>
                                                    </td>
                                                    <td>
                                                    <span style='color:<?= ($row['status'] == 0) ? 'red' : '' ?>'>
                                                        <?= ($row['status'] == 1) ? $lang['success'] : $lang['failure'] ?>
                                                    </span>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (count($result['data']) > 0) { ?>
                                    <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                                        <?= $lang['total'] ?> <?= $result['pagination']['total'] ?>
                                        <?= $lang['count'] ?>
                                    </div>
                                    <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                                        <?= $page->createPager($result['pagination']['pages'], $result['pagination']['current'], $lang) ?>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>