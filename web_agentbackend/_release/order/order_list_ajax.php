<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

$data = array();
parse_str($_SERVER['QUERY_STRING'], $data);

$result = curlPost('api/order/list', $data);
 if ($result['code'] != 1000) {
     echo display_error_msg_html($result["message"]);
     exit;
 }

$record = $result;
$record['pagination'] = (array) $result['pagination'];

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
                            <th style="width:15%;">
                                <?= $lang['ordernum'] ?> / <?= $lang['ordernum'] ?> （<?=$lang['deposit_vendor_name'] ?>）
                            </th>
                            <th>
                                <?= $lang['time'] ?>
                            </th>
                            <th>
                                <?= $lang['deposit_vendor_name'] ?> / <?= $lang['deposit_order_type_name'] ?>
                            </th>
                            <th>
                                <?= $lang['agent'] ?>
                            </th>
                            <th>
                                <?= $lang['member'] ?>
                            </th>
                            <th class="text-r">
                                <?= $lang['amount'] ?>
                            </th>
                            <th class="text-r">
                                <?= $lang['point'] ?>
                            </th>
                            <th class="text-c">
                                <?= $lang['order_status'] ?>
                            </th>
                            <th>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                    if (count($record['data']) > 0) {
                        foreach ($record['data'] as $row) {
                            ?>
                        <tr>
                            <td>
                                <div class="relative" title="<?= $row['id'] ?>">
                                    <div style="height:18px;">
                                        <?= $row['order_no'] ?>
                                    </div>
                                    <div style="height:18px;color:#AAA;">
                                        <?= $row['third_party_order_no'] ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span>
                                    <?= date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour')) ?>
                                </span>
                            </td>
                            <td>
                                <div>
                                    <span title="">
                                        <?= $row['cf_name'] ?>
                                    </span>
                                </div>
                                <div>
                                    <span title="<?= $row['order_type_id'] ?>" style="color:#AAA;">
                                        <?= $row['order_type_name'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <span title="<?= $row['agent_id'] ?>">
                                        <?= $row['agent_username'] ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <span title="<?= $row['member_id'] ?>">
                                    <?= $row['member_username'] ?>
                                </span>
                            </td>
                            <td class="text-r">
                                <?php if ($_SESSION['toggle_thousands'] == 0) {
                                    amount_thousands_off($row['amount'], 2);
                                } else {
                                    amount_thousands_on($row['amount'], 2);
                                }?>
                            </td>
                            <td class="text-r">
                                <?php if ($_SESSION['toggle_thousands'] == 0) {
                                    amount_thousands_off($row['point'], 2);
                                } else {
                                    amount_thousands_on($row['point'], 2);
                                }?>
                            </td>
                            <td class="text-c">
                                <span class="order_status_<?=$row['status']?>">
                                    <?= $row['status_name'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="dropdown inline-block">
                                    <button class="btn btn-default btn-sm dropdown-toggle" type="button"
                                        data-toggle="dropdown">
                                        <i class="ti-angle-down font-size-9"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a style="cursor:pointer;" onclick="previewPopup('<?= $row['id'] ?>', '<?= date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour')) ?>', '<?= date('Y-m-d H:i:s', strtotime($row['created_at']. '+9hour')) ?>')">
                                                <?=$lang["watch_data"]?>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>
                        <tr style="background: #fffaf4;">
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th class="text-r">
                                <?php if ($_SESSION['toggle_thousands'] == 0) {
                                    amount_thousands_off($result['total_summary']["total_amount"], 2);
                                } else {
                                    amount_thousands_on($result['total_summary']["total_amount"], 2);
                                }?>
                            </th>
                            <th class="text-r">
                                <?php if ($_SESSION['toggle_thousands'] == 0) {
                                    amount_thousands_off($result['total_summary']["total_point"], 2);
                                } else {
                                    amount_thousands_on($result['total_summary']["total_point"], 2);
                                }?>
                            </th>
                            <th class="text-c">
                            </th>
                            <th>
                            </th>
                        </tr>
                        <?php } else { ?>
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
<div class="modal fade" id="image">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="order_list">
        </div>
    </div>
</div>