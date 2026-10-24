<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';$data = array();

$queries = array();
parse_str($_SERVER['QUERY_STRING'], $queries);

$result = curlPost('api/orderType/paging-list', $_GET);

 if ($result['code'] != 1000) {
     echo display_error_msg_html($api_response["message"] , false);
     exit;
 }

$record = $result;
$record['pagination'] = (array) $result['pagination'];

$page = new CreatePage();

?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <div class="table-overflow">
                <div id="dt-opt_wrapper" class="dataTables_wrapper no-footer">
                    <table class="table table-lg table-hover no-footer">
                        <thead>
                            <tr>
                                <th><?= $lang['id'] ?></th>
                                <th><?= $lang['deposit_vendor_name'] ?></th>
                                <th><?= $lang['name'] ?></th>
                                <th> <?= $lang['code'] ?> </th>
                                <th><?= $lang['sorts'] ?> </th>
                                <th><?= $lang['picture'] ?> </th>
                                <th><?= $lang['status'] ?> </th>
                                <th><?= $lang['time'] ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($record['data']) > 0) {
                                $i = 0;
                                foreach ($record['data'] as $row) {?>

                            <?php 
                                $row = (array) $row;
                                $i++;
                            ?>

                            <tr>
                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["id"]?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15" title="<?= $row["cash_flow_id"]?>">
                                        <?= $row["cash_flow_name"]?>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["name"]?>
                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["key"]?>
                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["sorts"]?>
                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15">

                                        <?php $isImagePathExist = !(is_null($row["image_path"] || $row["image_path"] == '')) ?>

                                        <?php if($isImagePathExist){ ?>

                                        <img src="<?= $row["image_path"]?>" style="height:50px;">

                                        <?php } ?>

                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15">

                                        <?php if($row['status'] == 1) { ?>

                                        <span class="">
                                            <?= $lang['enable'] ?>
                                        </span>

                                        <?php }else{?>

                                        <span class="red">
                                            <?= $lang['disable'] ?>
                                        </span>

                                        <?php } ?>

                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15">
                                        <span><?=  date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour')) ?></span>
                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15">

                                        <div class="dropdown inline-block">
                                            <button class="btn btn-default btn-sm dropdown-toggle" type="button"
                                                data-toggle="dropdown">
                                                <i class="ti-angle-down font-size-9"></i>
                                            </button>

                                            <ul class="dropdown-menu">

                                                <li>
                                                    <a style="cursor:pointer;"
                                                        onclick="showEditModal('<?= $row["id"] ?>' , '<?= $row["name"] ?>' , '<?= $row["key"] ?>' , '<?= $row["sorts"] ?>' , <?= $row["status"] ?>, '<?= $row["image_path"] ?>')">
                                                        <?= $lang['edit'] ?>
                                                    </a>
                                                </li>

                                            </ul>
                                        </div>

                                    </div>
                                </td>

                            </tr>
                            <?php } } else { ?>
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
</div>
<style>
span.red {
    color: #dd5050;
}
</style>