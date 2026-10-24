<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';$data = array();

$queries = array();
parse_str($_SERVER['QUERY_STRING'], $queries);

$result = curlPost('api/vendor/account/list', $_GET);

 if ($result['code'] != 1000) {
     echo display_error_msg_html($result["message"], false);
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
                                <th><?= $lang['label_vendor'] . $lang['id'] ?></th>
                                <th><?= $lang['label_vendor'] ?></th>
                                <th><?= $lang['game'].$lang['member_account'] ?></th>
                                <th><?= $lang['member_account'] ?></th>
                                <th><?= $lang['nickname'] ?></th>
                                <th><?= $lang['agent'] ?></th>
                                <th><?= $lang["register_time"] ?></th>

                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($record['data']) > 0) {
                                $i = 0;
                                foreach ($record['data'] as $row) {
                                    $row = (array) $row;
                                    $i++; ?>


                            <tr>

                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["id"]?>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["vendor_id"]?>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["vendor_name"]?>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15 text-info">
                                        <?= $row["vendor_username"]?>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15" title=" <?= $row["member_id"]?>">
                                        <?= $row["member_username"]?>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15" title=" <?= $row["member_id"]?>">
                                        <?= $row["member_nickname"]?>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["agent_username"]?>
                                    </div>
                                </td>



                                <td>
                                    <div class="mrg-top-15">

                                        <span><?=  date('Y-m-d H:i:s', strtotime($row['member_created_at']. '+8hour')) ?></span>

                                    </div>
                                </td>


                            </tr>

                            <?php
                                }
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