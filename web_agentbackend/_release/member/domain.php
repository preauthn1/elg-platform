<?php
require_once '../_init.php';

$filter = array();
$filter['agent_id'] = $_POST['agent_id'];
$filter['show_all'] = 1;
$result = curlPost('api/agentDomain/self', $filter);
?>
<div class="modal-body">
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
                                <h4 class="card-title"><?= $lang['domain'] ?></h4>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th class="text-center"><?= $lang['id'] ?></th>
                                                <th class="text-center"><?= $lang['domain'] ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
										if (count($result['data']) > 0 && $result['code'] == 1000) {
											foreach ($result['data'] as $row) { ?>
                                            <tr>
                                                <td>
                                                    <span>
                                                        <?= $row['id'] ?>
                                                    </span>
                                                </td>
                                                <td >
                                                    <span>
                                                        <?= $row['domain'] ?>
                                                    </span>
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