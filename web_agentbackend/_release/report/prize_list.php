<?php

require_once '../_init.php';

$data = array();
$data['prize_1'] = $_POST['prize_1'];
$data['prize_2'] = $_POST['prize_2'];
$data['prize_3'] = $_POST['prize_3'];
$data['prize_4'] = $_POST['prize_4'];
$data['prize_5'] = $_POST['prize_5'];
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
                                <h4 class="card-title">
                                    <?=$lang['winning_list']?>
                                </h4>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th><?=$lang['awards']?></th>
                                                <th><?=$lang['winning_numbers']?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php for ($i = 1; $i < 6; $i++) { ?>
                                            <tr>
                                                <td>
                                                    <?= $lang['sr17_award_name_' . $i] ?>
                                                </td>
                                                <td>
                                                    <?= $data['prize_' . $i] ?>
                                                </td>
                                            </tr>
                                        <?php } ?>
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