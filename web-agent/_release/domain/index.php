<?php
require_once '../_init.php';
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
require_once '../_header.php';
require_once '../_menu.php';
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
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="table-overflow">
                            <table id="dt-opt" class="table table-lg table-hover">
                                <thead>
                                    <tr>
                                        <th><?=$lang['domain']?></th>                                                       
                                        <th><?=$lang['website_name']?></th>
                                        <th><?=$lang['webiste_logo']?></th>
                                        <th><?=$lang['status']?></th>
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
                                                <span class="text-info"><b><?=$row['domain']?></b></span>
                                            </div>
                                        </td>           
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><?=$row['title']?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="list-info mrg-top-10">
                                                <img height="40" src="<?=$row['logo_pc']?>" alt="">
                                            </div>
                                        </td>                                            
                                        <td>
                                            <div class="mrg-top-15">
                                                <?=($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-10 text-center">
                                                <div class="dropdown inline-block">
                                                    <button class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                                                        <i class="ti-angle-down font-size-9"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">                                                                        
                                                        <li>
                                                            <a href="edit.php?id=<?=$row['id']?>"><?=$lang['setting_template']?></a>
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