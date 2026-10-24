<?php
require_once '../_init.php';
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
                <h4>系統管理 > 版位管理 > 管理/查詢</h4>
            </div>
            <a href="edit.php" class="btn btn-inverse btn-success">建立版位</a>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>版位名稱</th>
                                            <th>欄數</th>
                                            <th>圖片寬度</th>
                                            <th>圖片高度</th> 
                                            <th>狀態</th>                                                       
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
                                                   	<?=($row['status'] == 1) ? '正常' : '停用'?>
                                                </div>
                                            </td>                                                
                                            <td>
                                                <a href="edit.php?id=<?=$row['id']?>" class="btn btn-default btn-sm">編輯</a>
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