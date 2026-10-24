<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['advertisementPath/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
$result = curlPost('api/advertisementPath/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
?>
<div class="page-container">
<?php
require_once '../_top.php';
require_once '../_theme.php';
?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['system_management'] ?> > <?= $lang['menu_advertisement_path'] ?></h4>
            </div>
            <div class="row">
                <a href="edit.php" class="btn btn-inverse btn-success"><?= $lang['create_a_slot'] ?></a>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['slot_name'] ?></th>
                                            <th><?= $lang['number_of_column'] ?></th>
                                            <th><?= $lang['picture_width'] ?></th>
                                            <th><?= $lang['picture_height'] ?></th>
                                            <th><?= $lang['status'] ?></th>
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
                                                    <span><?=$row['name']?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?=$row['column']?></span>
                                                </div>
                                            </td>
                                           
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20"><?=$row['width']?>px</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20"><?=$row['height']?>px</span>
                                                </div>
                                            </td>        
                                            <td>
                                                <div class="mrg-top-15 <?=($row['status'] != 1) ? 'text-danger' : ''?>">
                                                   	<?= ($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
                                                </div>
                                            </td>                                                
                                            <td>
                                                <a href="edit.php?id=<?=$row['id']?>" class="btn btn-default btn-sm">
                                                    <?= $lang['edit'] ?>
                                                </a>
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
<?php
require_once '../_bottom.php';
?>
</div>
<?php
require_once '../_footer.php';
?>