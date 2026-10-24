<?php
require_once '../_init.php';
$data = array();
$data['agent_id'] = $_POST['id'];
$data['page'] = 1;
$data['per_page'] = 20;
$result = curlPost('api/agentDomain/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
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
                                <h4 class="card-title"><?=$lang['domain_noted']?></h4>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th><?=$lang['domain']?></th>
                                                <th><?=$lang['enable_status']?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                         <?php
                                        foreach ($result['data'] as $domain) {
                                            ?>
                                            <tr>
                                                <td><?=$domain['domain']?></td>
                                                <td>
                                                  	<?=($domain['status'] == 1) ? '啟用' : '停用'?>
                                                </td>
                                            </tr>
                                            <?php
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
