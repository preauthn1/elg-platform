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
$data['start_time'] = ($_GET['start_date']
        ? $_GET['start_date']
        : date('Y-m-d 00:00', strtotime('-1day'))) . ':00';
$data['end_time'] = ($_GET['end_date']
        ? $_GET['end_date']
        : date('Y-m-d 00:00')) . ':00';
if ($_GET['type'] == 1 && $_GET['username'] != '') {
    $data['user_username'] = $_GET['username'];
} else if ($_GET['type'] == 2 && $_GET['username'] != '') {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 3 && $_GET['username'] != '') {
    $data['member_username'] = $_GET['username'];
}
$record = curlPost('api/newreport/platformReconciliationTotal', $data);
if ($record['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <div class="row">
                <div class="col-md-1" class="col-md-2 control-label">
                    <select class="form-control selectize" onchange="show(this.value);">
                        <option value="1"><?= $lang['only_agent'] ?></option>
                        <option value="2"><?= $lang['only_member'] ?></option>
                        <option value="0" selected><?= $lang['all'] ?></option>
                    </select>
                </div>
                <div class="col-md-11">
                    <button type="button" class="btn btn-success" id="excel" style="float:right"
                            data-username="<?= $_GET['username'] ?>" data-type="<?= $_GET['type'] ?>">
                        Excel
                    </button>
                </div>
            </div>
            <div class="table-overflow">
                <table class="table table-lg table-hover">
                    <thead>
                        <tr>
                            <th><?= $lang['nextlevel_detail'] ?></th>
                            <th><?= $lang['agent_account'] ?></th>
                            <th><?= $lang['agent_name'] ?></th>
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
                            $win_lose = 0;
                            $v_jackpot = 0;
                            $commission = 0;
                            $cost = 0;
                            foreach ($record['data'] as $row) {
                                $total_bet += $row['total_bet'];
                                $total_win_lose += $row['win_lose'];
                                $total_cost += $row['cost'];
                                $total_commission += $row['commission'];
                                $total_lottery += $row['lottery'];
                                $total_v_jackpot += $row['v_jackpot'];

                                if ($row['type'] == "member") {
                                    $m_total_bet += $row['total_bet'];
                                    $m_total_win_lose += $row['win_lose'];
                                    $m_total_cost += $row['cost'];
                                    $m_total_commission += $row['commission'];
                                    $m_total_lottery += $row['lottery'];
                                    $m_total_v_jackpot += $row['v_jackpot'];
                                } else {
                                    $a_total_bet += $row['total_bet'];
                                    $a_total_win_lose += $row['win_lose'];
                                    $a_total_cost += $row['cost'];
                                    $a_total_commission += $row['commission'];
                                    $a_total_lottery += $row['lottery'];
                                    $a_total_v_jackpot += $row['v_jackpot'];
                                }
                        ?>
                        <tr class="<?= (($row['type'] == "member") ? "member" : "agent") ?>">
                            <td>
                                <div class="mrg-top-15">
                                    <?php if ($row['vendor_id'] == -2 && $row['type'] == 'self') { ?>
                                    -
                                    <?php } else { ?>
                                    <a class="<?= ($row['type'] == 'agent') ? 'agent_top' : 'member_top' ?>"
                                        style="color:blue; cursor: pointer;"
                                        data-username="<?= $row['agent_username'] ?>">
                                        <?= $lang[$row['type']] ?>
                                    </a>
                                    <?php } ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['agent_username'] ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span><?= $row['agent_nickname'] ?></span>
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
                                    if ($row['vendor_id'] == 36) {
                                        echo '<span>--</span>';
                                    } else {
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
                                    }
                                    ?>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php
                                    if ($_SESSION['toggle_thousands'] == 0) {
                                        if($row['commission'] < 0){
                                            amount_thousands_off($row['commission'], 4, 'red');
                                        } else {
                                            amount_thousands_off($row['commission'], 4, 'blue');
                                        }
                                    } else {
                                        if($row['commission'] < 0){
                                            amount_thousands_on($row['commission'], 4, 'red');
                                        } else {
                                            amount_thousands_on($row['commission'], 4, 'blue');
                                        }
                                    }
                                    ?>
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
                                        <?php
                                        if ($_SESSION['toggle_thousands'] == 0) {
                                            if($row['cost'] < 0){
                                                amount_thousands_off($row['cost'], 4, 'red');
                                            } else {
                                                amount_thousands_off($row['cost'], 4, 'blue');
                                            }
                                        } else {
                                            if($row['cost'] < 0){
                                                amount_thousands_on($row['cost'], 4, 'red');
                                            } else {
                                                amount_thousands_on($row['cost'], 4, 'blue');
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>
                                <?php
                            }
                            ?>
                            <?php
                            if ($record['show_v_jackpot'] == true) { ?>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($row['v_jackpot'], 4);
                                        } else {
                                            amount_thousands_on($row['v_jackpot'], 4);
                                        }?>
                                    </div>
                                </td>
                            <?php
                            }
                            ?>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <?php
                                    $bite_score = $row['lottery'];
                                    if ($record['show_v_jackpot'] == true) {
                                        $bite_score += $row['v_jackpot'];
                                    }
                                    if ($_GET['cost_visible'] == 1) {
                                        $bite_score += $row['cost'];
                                    }
                                    $bite_score += ($row['vendor_id'] == 36) ? $row['commission'] : $row['win_lose'];

                                    if ($_SESSION['toggle_thousands'] == 0) {
                                        if($bite_score < 0){
                                            amount_thousands_off($bite_score, 4, 'red');
                                        } else {
                                            amount_thousands_off($bite_score, 4, 'blue');
                                        }
                                    } else {
                                        if($bite_score < 0){
                                            amount_thousands_on($bite_score, 4, 'red');
                                        } else {
                                            amount_thousands_on($bite_score, 4, 'blue');
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td class="panel agent<?= $row['agent_username'] . $row['vendor_id'] . $row['game_type_id'] ?>"
                                colspan="12" style="display: none;"></td>
                        </tr>
                        <tr>
                            <td class="panel member<?= $row['agent_username'] . $row['vendor_id'] . $row['game_type_id']  ?>"
                                colspan="12" style="display: none;"></td>
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
                                    <span id="total_bet"
                                          class = "<?= $_SESSION['toggle_thousands'] == 0 ? "total_thousands_off" : "total_thousands_on"?>"
                                          data-original="<?=number_format($total_bet, 4)?>"
                                          data-original-agent="<?=number_format($a_total_bet, 4)?>"
                                          data-original-member="<?=number_format($m_total_bet, 4)?>">
                                        <?= $_SESSION['toggle_thousands'] == 0 ? number_format($total_bet, 4) : number_format(($total_bet / 1000), 2).K?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <span id="total_win_lose"
                                          class = "<?= $_SESSION['toggle_thousands'] == 0 ? "total_thousands_off" : "total_thousands_on"?>"
                                          <?= ($total_win_lose < 0) ? "style='color:red'" : "" ?>
                                          data-original="<?=number_format($total_win_lose, 4)?>"
                                          data-original-agent="<?=number_format($a_total_win_lose, 4)?>"
                                          data-original-member="<?=number_format($m_total_win_lose, 4)?>">
                                        <?= $_SESSION['toggle_thousands'] == 0 ? number_format($total_win_lose, 4) : number_format(($total_win_lose / 1000), 2).K?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <span id="total_commission"
                                          class = "<?= $_SESSION['toggle_thousands'] == 0 ? "total_thousands_off" : "total_thousands_on"?>"
                                          data-original="<?=number_format($total_commission, 4)?>"
                                          data-original-agent="<?=number_format($a_total_commission, 4)?>"
                                          data-original-member="<?=number_format($m_total_commission,4)?>">
                                        <?= $_SESSION['toggle_thousands'] == 0 ? number_format($total_commission, 4) : number_format(($total_commission / 1000), 2).K?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <span id="total_lottery"
                                          class = "<?= $_SESSION['toggle_thousands'] == 0 ? "total_thousands_off" : "total_thousands_on"?>"
                                          data-original="<?=number_format($total_lottery, 4)?>"
                                          data-original-agent="<?=number_format($a_total_lottery, 4)?>"
                                          data-original-member="<?=number_format($m_total_lottery, 4)?>">
                                        <?= $_SESSION['toggle_thousands'] == 0 ? number_format($total_lottery, 4) : number_format(($total_lottery / 1000), 2).K?>
                                    </span>
                                </div>
                            </td>
                            <?php
                            if ($_GET['cost_visible'] == 1) {
                                ?>
                                <td>
                                    <div class="mrg-top-15 text-right">
                                    <span id="total_cost"
                                          class = "<?= $_SESSION['toggle_thousands'] == 0 ? "total_thousands_off" : "total_thousands_on"?>"
                                          data-original="<?=number_format($total_cost, 4)?>"
                                          data-original-agent="<?=number_format($a_total_cost, 4)?>"
                                          data-original-member="<?=number_format($m_total_cost,4)?>">
                                        <?= $_SESSION['toggle_thousands'] == 0 ? number_format($total_cost, 4) : number_format(($total_cost / 1000), 2).K?>
                                        </span>
                                    </div>
                                </td>
                                <?php
                            }
                            ?>
                            <?php if ($record['show_v_jackpot'] == true) { ?>
                            <td>
                                <div class="mrg-top-15 text-right">
                                    <span id="total_v_jackpot"
                                          class = "<?= $_SESSION['toggle_thousands'] == 0 ? "total_thousands_off" : "total_thousands_on"?>"
                                          data-original="<?=number_format($total_v_jackpot, 4)?>"
                                          data-original-agent="<?=number_format($a_total_v_jackpot, 4)?>"
                                          data-original-member="<?=number_format($m_total_v_jackpot, 4)?>">
                                        <?= $_SESSION['toggle_thousands'] == 0 ? number_format($total_v_jackpot, 4) : number_format(($total_v_jackpot / 1000), 2).K?>
                                    </span>
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
                                    ?>
                                    <span id="total_score"
                                          class = "<?= $_SESSION['toggle_thousands'] == 0 ? "total_thousands_off" : "total_thousands_on"?>"
                                          <?= ($total_bite_score < 0) ? "style='color:red'" : "" ?>
                                          data-original="<?=number_format($total_bite_score, 4)?>"
                                          data-original-agent="<?=number_format($a_total_bite_score, 4)?>"
                                          data-original-member="<?=number_format($m_total_bite_score, 4)?>">
                                        <?= $_SESSION['toggle_thousands'] == 0 ? number_format($total_bite_score, 4) : number_format(($total_bite_score / 1000), 2).K?>
                                    </span>
                                </div>
                            </td>
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
        </div>
    </div>
</div>
<script>
    function show(val){
        $('[class="agent"]').show();
        $('[class="member"]').show();
        //先取得千位數是否顯示
        var status = $("input[name=toggle_status]").attr('data-status');
        var type = '';
        if (val == 1) {
            $('[class="member"]').hide();
            type = "data-original-agent";
        } else if(val == 2) {
            $('[class="agent"]').hide();
            type = "data-original-member";
        } else {
            type = "data-original";
        }
        if (status == 1) {
            $(".total_thousands_on").each(function () {
                var amount = $(this).attr(type);
                amount = amount.replace("K", "");
                var balance = (RemoveComma(amount) / 1000);
                // $(this).text(balance.toFixed(2) + "K");
                $(this).text(DecimalNumber(balance, 2) + "K");
            });
        } else {
            $(".total_thousands_off").each(function () {
                var amount = $(this).attr(type);
                $(this).text(amount);
            });
        }
    }

    $(document).ready(function () {
        $(document).on('change', 'input[name=toggle_status]', function () {
            var status = $(this).attr('data-status');
            var val = $('#nextlevel_detail_filiter').val();
            var type = '';
            console.log('status:',status);
            if (val == 1) {
                $('[class="member"]').hide();
                type = "data-original-agent";
            } else if(val == 2) {
                $('[class="agent"]').hide();
                type = "data-original-member";
            } else {
                type = "data-original";
            }
            console.log('type:',type);
            if (status == 0) {
                $(".total_thousands_off").each(function () {
                    var amount = $(this).attr(type);
                    console.log('amount :', amount);
                    var balance = (RemoveComma(amount) / 1000);
                    // $(this).text(balance.toFixed(2) + "K");
                    $(this).text(DecimalNumber(balance, 2) + "K");
                    $(this).addClass('total_thousands_on').removeClass('total_thousands_off');
                });
            } else {
                $(".total_thousands_on").each(function () {
                    var amount = $(this).attr(type);
                    $(this).text(amount);
                    $(this).addClass('total_thousands_off').removeClass('total_thousands_on');
                });
            }
        });
    });
</script>