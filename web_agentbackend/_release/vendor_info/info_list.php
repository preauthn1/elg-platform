<?php
require_once '../_init.php';
$data = $_POST['data'];
?>
<div class="modal-body height-160">
    <a class="modal-close" href="" data-dismiss="modal" style="float: right;">
        <i class="ti-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                        <div class="card" style="width: 100%">
                            <div class="card-block">
                                <h4 class="card-title"> 相關資訊 </h4>
                                <div class="table-overflow">
                                    <table name="order_list" class="table table-striped">
                                        <?php
                                        $info = json_decode($data, true);
                                        foreach ($info as $key => $param) { ?>
                                            <tr>
                                                <th style="width:50%"><?= $key ?></th>
                                                <td style="width:50%;overflow-wrap:anywhere;"><?= $param ?></td>
                                            </tr>
                                        <?php } ?>
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

