<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['domain/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$data = array();
$data['page'] = 1;
$data['per_page'] = 100;
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
<!-- Page Container START -->
<div class="page-container">
    <?php
require_once '../_top.php';
require_once '../_theme.php';
?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?=$lang['system_management']?> > <?=$lang['management_domain_template']?></h4>
            </div>
            <div class="row">
                <a href="edit.php" class="btn btn-inverse btn-success"><?= $lang['add'] ?></a>

                <button type="button" class="btn btn-inverse btn-danger" onclick="triggerRedisFlushDB()">
                    清除Redis快取
                </button>

                <a href="/internal-helper/excel_helper.php" class="btn btn-inverse btn-warning" target="_blank">
                    多語系轉換工具
                </a>

                <a href="/member/compare_summaryBet_vs_totalBet.php" class="btn btn-inverse btn-primary"
                    target="_blank">
                    投注數據異常比對工具
                </a>

            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt2" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?=$lang['id']?></th>
                                            <th><?=$lang['domain']?></th>
                                            <th><?=$lang['website_name']?></th>

                                            <th><?=$lang['agent']." ID"?></th>
                                            <th><?=$lang['agent']?></th>
                                            <th><?=$lang['agent']." ".$lang['organization']?></th>

                                            <th><?=$lang['webiste_logo']?></th>
                                            <th><?=$lang['status']?></th>
                                            <th><?=$lang['operating_time']?></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                foreach ($result['data'] as $row) {
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class=""><b><?=$row['id']?></b></span>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?=$row['domain']?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class=""><?=$row['title']?></span>
                                                </div>
                                            </td>


                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class=""><?=$row['agent_id']?></span>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class=""><?=$row['username']?></span>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class=""><?=$row['organization_name']?></span>
                                                </div>
                                            </td>


                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <img height="40" src="<?=$row['logo_pc']?>" alt="">
                                                </div>
                                            </td>

                                            <td>
                                                <div class="mrg-top-15">

                                                    <span class="<?='status_'.$row['status']?>">
                                                        <?=($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
                                                    </span>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?=  date('Y-m-d H:i:s', strtotime($row['updated_at']. '+8hour')) ?></span>
                                                </div>
                                            </td>


                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button class="btn btn-default btn-sm dropdown-toggle"
                                                            type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a
                                                                    href="edit.php?id=<?=$row['id']?>"><?=$lang['setting_template']?></a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
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
    <!-- Content Wrapper END -->
    <?php
require_once '../_bottom.php';
?>
</div>
<!-- Page Container END -->
<?php
require_once '../_footer.php';
?>



<script>
$(function() {

})

function triggerRedisFlushDB() {

    $.blockUI();
    let form = "action=redis_flushDB";

    $.post("<?= BASE_URL ?>ajax.php", form, function(data) {

        $.unblockUI();
        if (data.code == 1000) {
            alert('清除成功!');
        } else {
            alert(data.message);
        }

    }, 'json');
}
</script>




<style>
.status_0 {
    color: #eb6262;
}
</style>