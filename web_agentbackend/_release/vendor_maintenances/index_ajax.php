<?php

require_once '../_init.php';
require_once '../class/CreatePage.php';

$data = array();
$data['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$data['per_page'] = 20;
$record = curlPost('api/vendor_maintenances/list', $data);
if ($record['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
}

$page = new CreatePage();

?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <div class="table-overflow">
                <table id="dt-opt2" class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['createdate'] ?></th>
                            <th><?= $lang['label_vendor'].$lang['name'] ?></th>
                            <th><?= $lang['type'] ?></th>
                            <th><?= $lang['label_start_time'] ?></th>
                            <th><?= $lang['label_end_time'] ?></th>
                            <th><?= $lang['maintenance_agent'] ?></th>
                            <th><?= $lang['exclude_agent'] ?></th>
                            <th><?= $lang['enable_status'] ?></th>
                            <th><?= $lang['last_operator'] ?></th>
                            <th><?= $lang['features'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($record['data'] as $row) {
                                $now = date('Y-m-d H:i:s');
                                $isExpired = $row["end_time"] < $now;
                                $langKey = "vendor_maintenances_type_" . ((string)$row['type']);
                                $cssClass = $isExpired ? "grey" : "";
                        ?>
                        <tr>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span>
                                        <?= date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour')) ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class=""><?= $row['vendor_name'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class=""><b><?= $lang[$langKey] ?></b></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class=" text-info <?= $cssClass ?> ">
                                        <b>
                                            <?= date('Y-m-d H:i:s', strtotime($row['start_time']. '+8hour')) ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class=" text-info <?= $cssClass ?>">
                                        <b>
                                            <?= date('Y-m-d H:i:s', strtotime($row['end_time']. '+8hour')) ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class=""><?= $row['agent_username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class=""><?= $row['exclude_agent_username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class="<?= $row['status'] != 1 ? "red" : "" ?>">
                                        <b>
                                            <?= $row['status'] == 1 ? $lang['enable'] : $lang['disable'] ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="relative mrg-top-15">
                                    <span class=""><?= $row['username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="dropdown inline-block">
                                    <button class="btn btn-default btn-sm dropdown-toggle" type="button"
                                        data-toggle="dropdown">
                                        <i class="ti-angle-down font-size-9"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a href="edit.php?id=<?= $row['id'] ?>"><?= $lang['edit'] ?></a>
                                        </li>
                                    </ul>
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
            <?php } ?>
        </div>
    </div>
</div>
<script>
$(document).ready(function() {

});
</script>
<style>
.red {
    color: #f21a1a;
}

.grey {
    color: #CCC !important;
    font-weight: 100 !important;
}

.paginate_button {
    cursor: pointer;
}
</style>