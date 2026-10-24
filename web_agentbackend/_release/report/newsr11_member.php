<?php
require_once '../_init.php';
require_once '../commonFunction.php';

//取對應語言包
$translates = curlPost('api/translate');
$lab_jackpot = isset($translates['data']['3004']) ? $translates['data']['3004'] : 'Jackpot';
$lab_pay_fee = isset($translates['data']['3006']) ? $translates['data']['3006'] : 'Pay fees';
$lab_sms_fee = isset($translates['data']['3007']) ? $translates['data']['3007'] : 'SMS fee';
$lab_cost = isset($translates['data']['3008']) ? $translates['data']['3008'] : 'Cost';

$data = array();
$data['vendor_id'] = $_GET['vendor_id'] ? $_GET['vendor_id'] : 0;
$data['game_type'] = $_GET['game_type'] ? $_GET['game_type'] : 0;
$data['start_time'] = ($_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d 00:00', strtotime('-1day'))) . ':00';
$data['end_time'] = ($_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d 00:00')) . ':00';
if ($_GET['type'] == 1 && $_GET['username'] != '') {
    $data['user_username'] = $_GET['username'];
} else if ($_GET['type'] == 2 && $_GET['username'] != '') {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 3 && $_GET['username'] != '') {
    $data['member_username'] = $_GET['username'];
}
$record = curlPost('api/newreport/platformReconciliationMember', $data);
if ($record['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <button type="button" class="btn btn-success" id="excel_member" style="float:right"
                data-username="<?= $_GET['username'] ?>" data-type="<?= $_GET['type'] ?>">Excel</button>
            <div class="table-overflow">
                <table class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['num'] ?></th>
                            <th><?= $lang['member_account'] ?></th>
                            <th><?= $lang['member_name'] ?></th>
                            <th><?= $lang['platform'] ?></th>
                            <th><?= $lang['game_type'] ?></th>
                            <th class="text-right"><?= $lang['total_bet'] ?></th>
                            <th class="text-right"><?= $lang['total_winlose'] ?></th>
                            <th class="text-right"><?= $lang['commission'] ?></th>
                            <th class="text-right"><?= $lang['jackpot_event'] ?></th>
                            <?php
                            if ($_GET['cost_visible'] == 1) {
                                ?>
                                <th class="text-right"><?= $lab_cost ?></th>
                                <?php
                            }
                            ?>
                            <?php if ($record['show_v_jackpot'] == true) { ?>
                            <th class="text-right"><?= $lab_jackpot ?></th>
                            <?php } ?>
                            <th class="text-right"><?= $lang['total_score'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (count($record['data']) > 0) {
                            $i = 0;
                            $total_bet = 0;
                            $total_win_lose = 0;
                            $total_lottery = 0;
                            $total_v_jackpot = 0;
                            $total_cost = 0;
                            $total_commission = 0;
                            foreach ($record['data'] as $row) {
                                $total_bet += $row['total_bet'];
                                $total_win_lose += $row['win_lose'];
                                $total_lottery += $row['lottery'];
                                $total_v_jackpot += $row['v_jackpot'];
                                $total_cost += $row['cost'];
                                $total_commission += $row['commission'];
                                $i++;
                        ?>
                        <tr>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $i ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $row['member_username'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $row['member_nickname'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span class="text-info">
                                        <b>
                                            <?= ($row['vendor'] == 'payfee') ? $lab_pay_fee : ($row['vendor'] == 'smsfee' ? $lab_sms_fee : $row['vendor']) ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span>
                                        <?= $row['game_type'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php
                                    if ($row['vendor_id'] == 36) {
                                        echo '<span>--</span>';
                                    } else {
                                        if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['total_bet'], 4);
                                        } else {
                                            amount_thousands_on($row['total_bet'], 4);
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php
                                    if ($_SESSION['toggle_thousands'] == 0) {
                                        if($row['win_lose'] < 0){
                                            amount_thousands_off($row['win_lose'], 4, 'red');
                                        } else {
                                            amount_thousands_off($row['win_lose'], 4);
                                        }
                                    } else {
                                        if($row['win_lose'] < 0){
                                            amount_thousands_on($row['win_lose'], 4, 'red');
                                        } else {
                                            amount_thousands_on($row['win_lose'], 4);
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
                                <div class="mrg-top-15 text-right">
                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                        amount_thousands_off($row['lottery'], 4);
                                    } else {
                                        amount_thousands_on($row['lottery'], 4);
                                    }?>
                                </div>
                            </td>
                            <?php
                            if ($_GET['cost_visible'] == 1) {
                                ?>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['cost'], 4);
                                        } else {
                                            amount_thousands_on($row['cost'], 4);
                                        }?>
                                    </div>
                                </td>
                                <?php
                            }
                            ?>
                            <?php if ($record['show_v_jackpot'] == true) { ?>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                        amount_thousands_off($row['v_jackpot'], 4);
                                    } else {
                                        amount_thousands_on($row['v_jackpot'], 4);
                                    }?>
                                </div>
                            </td>
                            <?php } ?>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php
                                    $bite_score = $row['lottery'];
                                    if ($record['show_v_jackpot'] == true) {
                                        $bite_score += $row['v_jackpot'];
                                    }
                                    $bite_score += ($row['vendor_id'] == 36) ? $row['commission'] : $row['win_lose'];
                                    if ($_GET['cost_visible'] == 1) {
                                        $bite_score += $row['cost'];
                                    }

                                    if ($_SESSION['toggle_thousands'] == 0) {
                                        if($bite_score < 0){
                                            amount_thousands_off($bite_score, 4, 'red');
                                        } else {
                                            amount_thousands_off($bite_score, 4);
                                        }
                                    } else {
                                        if($bite_score < 0){
                                            amount_thousands_on($bite_score, 4, 'red');
                                        } else {
                                            amount_thousands_on($bite_score, 4);
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>
                            <tr>
                                <td colspan="5" align='center'>
                                    <div class="mrg-top-15">
                                        <span class="text-info">
                                            <b>
                                                <?= $lang['total_count'] ?>
                                            </b>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15" style="text-align: right;">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($total_bet, 4);
                                        } else {
                                            amount_thousands_on($total_bet, 4);
                                        }?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php
                                        if ($_SESSION['toggle_thousands'] == 0) {
                                            if($total_win_lose < 0){
                                                amount_thousands_off($total_win_lose, 4, 'red');
                                            } else {
                                                amount_thousands_off($total_win_lose, 4);
                                            }
                                        } else {
                                            if($total_win_lose < 0){
                                                amount_thousands_on($total_win_lose, 4, 'red');
                                            } else {
                                                amount_thousands_on($total_win_lose, 4);
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php
                                        if ($_SESSION['toggle_thousands'] == 0) {
                                            if($total_commission < 0){
                                                amount_thousands_off($total_commission, 4, 'red');
                                            } else {
                                                amount_thousands_off($total_commission, 4);
                                            }
                                        } else {
                                            if($total_commission < 0){
                                                amount_thousands_on($total_commission, 4, 'red');
                                            } else {
                                                amount_thousands_on($total_commission, 4);
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($total_lottery, 4);
                                        } else {
                                            amount_thousands_on($total_lottery, 4);
                                        }?>
                                    </div>
                                </td>
                                <?php
                                if ($_GET['cost_visible'] == 1) {
                                    ?>
                                    <td>
                                        <div class="mrg-top-15 text-right">
                                            <?php
                                            if ($_SESSION['toggle_thousands'] == 0) {
                                                if($total_cost < 0){
                                                    amount_thousands_off($total_cost, 4, 'red');
                                                } else {
                                                    amount_thousands_off($total_cost, 4);
                                                }
                                            } else {
                                                if($total_cost < 0){
                                                    amount_thousands_on($total_cost, 4, 'red');
                                                } else {
                                                    amount_thousands_on($total_cost, 4);
                                                }
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <?php
                                }
                                ?>
                                <?php if ($record['show_v_jackpot'] == true) { ?>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($total_v_jackpot, 4);
                                        } else {
                                            amount_thousands_on($total_v_jackpot, 4);
                                        }?>
                                    </div>
                                </td>
                                <?php } ?>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php
                                        $total_bite_score = ($record['show_v_jackpot'] == true)
                                            ? ($total_win_lose + $total_lottery + $total_commission + $total_v_jackpot)
                                            : ($total_win_lose + $total_lottery + $total_commission);

                                        if ($_GET['cost_visible'] == 1) {
                                            $total_bite_score += $total_cost;
                                        }

                                        if ($_SESSION['toggle_thousands'] == 0) {
                                            if($total_bite_score < 0){
                                                amount_thousands_off($total_bite_score, 4, 'red');
                                            } else {
                                                amount_thousands_off($total_bite_score, 4);
                                            }
                                        } else {
                                            if($total_bite_score < 0){
                                                amount_thousands_on($total_bite_score, 4, 'red');
                                            } else {
                                                amount_thousands_on($total_bite_score, 4);
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>
                            </tr>
                        <?php } else { ?>
                        <tr>
                            <td colspan="14" align='center'>
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
        </div>
    </div>
</div>