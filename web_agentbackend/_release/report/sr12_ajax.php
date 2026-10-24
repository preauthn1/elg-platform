<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

if ($_GET['act'] == 1) {
    $filter = array();
    $filter['vendor_id'] = $_GET['vendor_id'] ? $_GET['vendor_id'] : 1;
    $filter['game_type'] = $_GET['game_type'] ? $_GET['game_type'] : 0;
    if ($_GET['type'] == 1 && $_GET['username'] != '') {
        $filter['agent_username'] = $_GET['username'];
    } else if ($_GET['type'] == 2 && $_GET['username'] != '') {
        $filter['member_username'] = $_GET['username'];
    } else if ($_GET['type'] == 3 && $_GET['username'] != '') {
        $filter['game_no'] = $_GET['username'];
    }
    $filter['game_id'] = $_GET['game_id'] ? $_GET['game_id'] : 0;
    $filter['sort_by'] = $_GET['sort_by'] ? $_GET['sort_by'] : 1;
    $filter['whitch_time'] = $_GET['whitch_time'] ? $_GET['whitch_time'] : 1;
    $filter['page'] = $_GET['page'] ? $_GET['page'] : 1;
    $filter['per_page'] = $_GET['per_page'] ? $_GET['per_page'] : 25;
    $filter['start_time'] = ($_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00')) . ':00';
    $filter['end_time'] = ($_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59')) . ':59';
    $record = curlPost('api/report/sr12', $filter);
    if ($record['code'] >= 9997) {
        header("location:" . BASE_URL . "login.php");
        exit();
    } elseif ($record['code'] == 1000) {
        $data = $record['data'];
        $summary_data = $record['summary_data'];
    }
}

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
                                <th><?= $lang['bet_time'] ?></th>
                                <th><?= $lang['agent_account'] ?></th>
                                <th><?= $lang['member_account'] ?></th>
                                <th><?= $lang['game_name'] ?></th>
                                <th class="text-right"><?= $lang['bet'] ?></th>
                                <th class="text-right"><?= $lang['valid_bet'] ?></th>
                                <th class="text-right"><?= $lang['payout'] ?></th>
                                <th class="text-right"><?= $lang['winlose'] ?></th>
                                <th class="text-right"><?= $lang['commission'] ?></th>
                                <th><?= $lang['ordernumber'] ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($data) > 0) {
                                $i = 0;
                                foreach ($data as $row) {
                                    $i++;
                            ?>
                            <tr>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['bet_time'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['agent_username'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['member_username'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span class="text-info" title="<?= $row["vendor_name"] ?>">
                                            <b><?= $row['game_name'] ?></b>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['bet_amount'], 4);
                                        } else {
                                            amount_thousands_on($row['bet_amount'], 4);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['valid_bet_amount'], 4);
                                        } else {
                                            amount_thousands_on($row['valid_bet_amount'], 4);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['lottery'], 4);
                                        } else {
                                            amount_thousands_on($row['lottery'], 4);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php
                                        if ($_SESSION['toggle_thousands'] == 0) {
                                            if($row['win_lose'] < 0){
                                                amount_thousands_off($row['win_lose'], 4, 'red');
                                            } else {
                                                amount_thousands_off($row['win_lose'], 4, 'blue');
                                            }
                                        } else {
                                            if($row['win_lose'] < 0){
                                                amount_thousands_on($row['win_lose'], 4, 'red');
                                            } else {
                                                amount_thousands_on($row['win_lose'], 4, 'blue');
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['commission'], 4);
                                        } else {
                                            amount_thousands_on($row['commission'], 4);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?= $row['game_no'] ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
                                <tr>
                                    <td colspan="3">&nbsp;</td>
                                    <td>
                                        <div class="mrg-top-15">
                                            <?= $lang['total_count'] ?>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <div class="mrg-top-15">
                                            <span <?= ($summary_data['bet_amount'] < 0) ? "style='color:red'" : "" ?>>
                                                 <b>
                                                    <?php
                                                    if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($summary_data['bet_amount'], 2);
                                                    } else {
                                                        amount_thousands_on($summary_data['bet_amount'], 2);
                                                    }
                                                    ?>
                                                </b>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <div class="mrg-top-15">
                                            <span <?= ($summary_data['valid_bet_amount'] < 0) ? "style='color:red'" : "" ?>>
                                                 <b>
                                                    <?php
                                                    if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($summary_data['valid_bet_amount'], 2);
                                                    } else {
                                                        amount_thousands_on($summary_data['valid_bet_amount'], 2);
                                                    }
                                                    ?>
                                                </b>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <div class="mrg-top-15">
                                            <span <?= ($summary_data['payout'] < 0) ? "style='color:red'" : "" ?>>
                                                <b>
                                                    <?php
                                                    if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($summary_data['payout'], 2);
                                                    } else {
                                                        amount_thousands_on($summary_data['payout'], 2);
                                                    }
                                                    ?>
                                                </b>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <div class="mrg-top-15">
                                            <span <?= ($summary_data['win_lose'] < 0) ? "style='color:red'" : "" ?>>
                                                <b>
                                                    <?php
                                                    if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($summary_data['win_lose'], 2);
                                                    } else {
                                                        amount_thousands_on($summary_data['win_lose'], 2);
                                                    }
                                                    ?>
                                                </b>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <div class="mrg-top-15">
                                            <span <?= ($summary_data['commission'] < 0) ? "style='color:red'" : "" ?>>
                                                <b>
                                                    <?php
                                                    if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($summary_data['commission'], 2);
                                                    } else {
                                                        amount_thousands_on($summary_data['commission'], 2);
                                                    }
                                                    ?>
                                                </b>
                                            </span>
                                        </div>
                                    </td>
                                    <td>&nbsp;</td>
                                </tr>
                                <?php } else { ?>
                            <tr>
                                <td colspan="9" align='center'>
                                    <div class="mrg-top-15">
                                        <span class="text-info"><b><?= $lang['no_record'] ?></b></span>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
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