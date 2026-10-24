<?php

require_once '../_init.php';

$data = array();
$data['period_id'] = $_POST['period_id'];
$_POST['type'] = isset($_POST['type']) ? $_POST['type'] : 1;
if ($_POST['type'] == 2) {
    $result = curlPost('api/w_jackpot/winningList', $data);
} else {
    $result = curlPost('api/v_jackpot/winningList', $data);
}

if ($result['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
}

?>
<div class="modal-body height-160">
	<a class="modal-close" href="" data-dismiss="modal" style="float: right;">
        <i class="ti-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title"><?=$lang['winning_list']?></h4>
                                <button type="button" class="btn btn-success" id="excel_winning_list"
                                        data-period_id="<?= $_POST['period_id'] ?>"
                                        data-type="<?= $_POST['type'] ?>"
                                        style="float:right;">Excel</button>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th><?=$lang['agent_name']?></th>
                                                <th><?=$lang['member_name']?></th>
                                                <th><?=$lang['lottery_ticket_no']?></th>
                                                <th class="text-right"><?=$lang['bonus']?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        if ($result['code'] == 1000) {
                                            foreach ($result['data'] as $winnings) {
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?=$winnings['agent_username']?>
                                                    </td>
                                                    <td>
                                                        <?=$winnings['member_username']?>
                                                    </td>
                                                    <td>
                                                        <?=$winnings['ticket_number']?>
                                                    </td>
                                                    <td class="text-right">
                                                        <?= ($winnings['prize_type'] == 2) ? $winnings['point'] : number_format($winnings['point'], 0) ?>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    $(document).ready(function() {
        $("#excel_winning_list").on('click', function(e) {
            var period_id = $(this).attr('data-period_id');
            var type = $(this).attr('data-type');
            $.blockUI();
            if (type == 2) {
                window.location = 'ajax.php?action=sr17_winning_list' +
                    '&period_id=' + period_id + '&type=2';
            } else {
                window.location = 'ajax.php?action=sr16_winning_list' +
                    '&period_id=' + period_id;
            }
            setTimeout($.unblockUI, 5000);
        });
    });
</script>