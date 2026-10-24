<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';$data = array();

$queries = array();
parse_str($_SERVER['QUERY_STRING'], $queries);

$result = curlPost('api/agent/operate/logs', $queries);

 if ($result['code'] != 1000) {
     echo display_error_msg_html($api_response["message"]);
     exit;
 }

$record = $result;
$record['pagination'] = (array) $result['pagination'];

$page = new CreatePage();

?>
<!-- 表格 遊戲記錄-->
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <div class="table-overflow">
                <div id="dt-opt_wrapper" class="dataTables_wrapper no-footer">
                    <!-- <table id="dt-opt" class="table table-lg table-hover"> -->
                    <table class="table table-lg table-hover no-footer">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?= $lang['time'] ?></th>
                                <th><?= $lang['type'] ?></th>
                                <th><?= $lang['operator'] ?></th>
                                <th><?= $lang['update_target'] ?></th>
                                <th><?= $lang['ip'] ?></th>
                                <th></th>
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
                                        <span><?=  date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour')) ?></span>
                                    </div>
                                </td>



                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row["type_name"]?>
                                        </span>
                                    </div>
                                </td>



                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row["operator"]?>
                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row["targetUserName"]?>
                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row["ip"]?>
                                        </span>
                                    </div>
                                </td>



                                <td>


                                    <div class="dropdown inline-block">

                                    </div>

                                </td>




                            </tr>
                            <?php
                                }
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
                <?php
                } ?>
            </div>
        </div>
    </div>
</div>


<script>
function deleteItem(id) {

    let msg = "<?=$lang['are_you_sure'] ?>";

    if (confirm(msg)) {

        let form = "action=delete_announcement_messages&announcement_message_id=" + id;

        $.post("<?= BASE_URL ?>ajax.php", form, function(data) {
            $.unblockUI();

            if (data.code == 1000) {

                alert(data.message);
                loadTableData(1);

            } else {
                alert(data.message + "." + data.data);
            }
        }, 'json');

    }
}
</script>


<style>
span.red {
    color: #dd5050;
}
</style>