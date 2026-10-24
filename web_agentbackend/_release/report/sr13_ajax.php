<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

$data = array();

$data['username'] = isset($_GET['username']) ? $_GET['username'] : "frank";
$start_date = isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d H:i:s', strtotime('-1day'));
$end_date = isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d H:i:s');

$data['start_time'] = date('Y-m-d H:i', strtotime( $start_date. '-8hour'))  ;
$data['end_time'] =date('Y-m-d H:i', strtotime( $end_date. '-8hour'));;
$data['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$data['per_page'] = 20;


$client = new \target\resource\Clients\Client('internal');
$result = $client->send('AgentMoneyChangeReport', 'GET', [], $data);

if($result->statusCode != 200) {
    header("location:" . BASE_URL . "login.php");
    exit();
}

$record = (array) $result->data;
$record['pagination'] = (array) $record['pagination'];


// if ($record['code'] >= 9997) {
//     header("location:" . BASE_URL . "login.php");
//     exit();
// }


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
                                <!--<th><? #=$lang['num']
                                        ?></th>-->
                                <th><?= $lang['time'] ?></th>
                                <th><?= $lang['ordernum'] ?></th>
                                <th><?= $lang['type'] ?></th>
                                <th><?= $lang['agent_account']."(from)" ?></th>
                                <th><?= $lang['agent_account']."(to)" ?></th>
                                <th><?= $lang['member_account'] ?></th>
                                <th class="text-right"><?= $lang['moment_point'] ?></th>
                                <th class="text-right"><?= $lang['change_point'] ?></th>
                                <th><?= $lang['status'] ?></th>
                                <th><?= $lang['memo'] ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($record['data']) > 0) {
                                $i = 0;
                                foreach ($record['data'] as $row) {

                                    $row = (array) $row;
                                    $isAgent_to_Agent = $row["isAgent_to_Agent"];      
                                    $prefixText =  "(".($isAgent_to_Agent ? $lang["agent"] : $lang["player"]).")";
                                    $type_name = $lang[$row["type_name_lang_key"]].$prefixText;   
                                    $status_text =  $isAgent_to_Agent ? $lang["status_".$row["status"]] :  $lang["chat_transaction_status_".$row["status"]];

                                    $i++;
                            ?>
                            <tr>


                                <td>
                                    <div class="mrg-top-15">
                                        <span><?=  date('Y-m-d H:i:s', strtotime( $row['created_at']. '+8hour')) ?></span>
                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15" title="<?=$row['id']?>">
                                        <span>

                                            <?= $row['transaction_no'] ?>

                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <span>

                                            <?=  $type_name ?>

                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['from_username'] ?></span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <span>

                                            <?=   $isAgent_to_Agent ? $row['to_username'] : "-"  ?>

                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15">
                                        <span>

                                            <?=  $isAgent_to_Agent ? "-" :  $row['member_username']  ?>

                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15 text-right" title="<?=$row['after_credit'] ?>">
                                        <?php
                                        if($row['my_credit'] == -1){
                                            echo '<span>∞</span>';
                                        } else {
                                            if ($_SESSION['toggle_thousands'] == 0) {
                                                amount_thousands_off($row['before_credit']);
                                            } else {
                                                amount_thousands_on($row['before_credit']);
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <span class="<?= $_SESSION['toggle_thousands'] == 0 ? "thousands_off" : "thousands_on"?>
                                              <?= $row["is_minus"] ? "red" : "" ?>"
                                              data-original="<?=number_format($row['amount'], 2)?>">
                                        <?= $_SESSION['toggle_thousands'] == 0 ? number_format($row['amount'], 2) : number_format(($row['amount'] / 1000), 2).K?>
                                        </span>
                                    </div>
                                </td>


                                <td>
                                    <div class="mrg-top-15" title="<?=$row['status']?>">
                                        <span><?= $status_text ?></span>
                                    </div>
                                </td>

                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['remark'] ?></span>
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

</script>


<style>
span.red {
    color: #dd5050;
}
</style>