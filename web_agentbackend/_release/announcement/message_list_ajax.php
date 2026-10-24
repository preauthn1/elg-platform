<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';$data = array();


$data['username'] = isset($_GET['username']) ? $_GET['username'] : "frank";
$start_date = isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d H:i:s', strtotime('-1day'));
$end_date = isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d H:i:s');

$data['start_time'] = date('Y-m-d H:i', strtotime( $start_date. '-8hour'))  ;
$data['end_time'] =date('Y-m-d H:i', strtotime( $end_date. '-8hour'));;
$data['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$data['per_page'] = 20;


$result = curlPost('api/announcement-messages/list', $data);
 if ($result['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
}

$record = $result;
$record['pagination'] = (array) $result['pagination'];

$is_delete_permission_exist = check_permission_menuCode('announcement_message_delete');

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
                                <th><?= $lang['time'] ?></th>
                                <th><?= $lang['type'] ?></th>
                                <th><?= $lang['sendMessage_target'] ?></th>
                                <th><?= $lang['content'] ?></th>
                                <th><?= $lang['memo'] ?></th>
                                <th>
                                    <?= $lang['label_start_time'] ?>／<?= $lang['label_end_time'] ?>
                                </th>
                                <th><?= $lang['created_operator'] ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($record['data']) > 0) {
                                $i = 0;
                                foreach ($record['data'] as $row) {
                                    $row = (array) $row;                               
                                    $i++;
                                    $type_text = $lang["announcement_messages_participants_type_".$row["participants_type"]];
                            ?>

                            <tr>


                                <td>
                                    <div class="mrg-top-15">
                                        <span><?=  date('Y-m-d H:i:s', strtotime( $row['created_at']. '+8hour')) ?></span>
                                    </div>
                                </td>



                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $type_text ?>
                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row['target_name'] ?>
                                        </span>
                                    </div>
                                </td>



                                <td style="width: 33%;">
                                    <div class="mrg-top-15" title="<?=$row['id']?>">
                                        <span>
                                            <?= $row['message'] ?>
                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row['memo'] ?>
                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">

                                        <div>
                                            <span><?=  date('Y-m-d H:i:s', strtotime( $row['start_time']. '+8hour')) ?></span>
                                        </div>

                                        <div>
                                            <span><?=  date('Y-m-d H:i:s', strtotime( $row['end_time']. '+8hour')) ?></span>
                                        </div>

                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row['op_username'] ?>
                                        </span>
                                    </div>
                                </td>



                                <td>


                                    <div class="dropdown inline-block">


                                        <!-- 判斷是否有刪除權限 -->
                                        <?php if($is_delete_permission_exist) { ?>

                                        <button class="btn btn-default btn-sm dropdown-toggle" type="button"
                                            data-toggle="dropdown">
                                            <i class="ti-angle-down font-size-9"></i>
                                        </button>

                                        <ul class="dropdown-menu">

                                            <li onclick="deleteItem('<?= $row['id'] ?>')">

                                                <a href="javascript:void(0)">
                                                    <?= $lang['delete'] ?>
                                                </a>
                                            </li>

                                        </ul>

                                        <?php  }  ?>


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
                <?php } ?>
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