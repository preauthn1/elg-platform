<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
?>
<!-- Page Container START -->
<div class="page-container">
<?php
require_once '../_top.php';
require_once '../_theme.php';
// 廠商列表
$result = curlPost('api/vendor/list');
if ($result['code'] == 1000) {
    $vendors = array();
    foreach ($result['data'] as $row) {
        $vendors[$row['id']] = $row['name'];
    }
}
// 遊戲類型
$result = curlPost('api/gameType/list');
if ($result['code'] == 1000) {
    $types = array();
    foreach ($result['data'] as $row) {
        if ($row['parent'] == 0) {
            $types[$row['id']] = $row['name'];
        } else {
            $types[$row['id']] = $types[$row['parent']] . '>' . $row['name'];
        }
    }
}
$data = [
    'page' => 1,
    'per_page' => 1000
];
if ($_GET['vid']) {
    $data['vendor_id'] = $_GET['vid'];
}
$result = curlPost('api/game/list', $data);
if ($result['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
?>
<!-- Content Wrapper START -->
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>遊戲商管理 > 廠商管理 > 遊戲管理</h4>
        </div>
        <div class="row">

            <div class="pdd-horizon-30 pdd-vertical-20" style="text-align:center">
                <a href="javascript:history.back()" class="btn btn-success btn-inverse btn-rounded">回上頁</a>
                <a href="edit.php" class="btn btn-danger btn-inverse btn-rounded">新增遊戲</a>
            </div>
            
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="table-overflow">
                            <table id="dt-opt" class="table table-lg table-hover">
                                <thead>
                                    <tr>
                                        <th>廠商名稱</th>
                                        <th>遊戲代碼</th>
                                        <th>遊戲類型</th>
                                        <th>遊戲名稱</th>
                                        <th>圖片</th>
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
                                            <div class="list-info mrg-top-10">
                                                <div class="info">
                                                    <span class="title"><?=$vendors[$row['vendor_id']]?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="list-info mrg-top-10">
                                                <div class="info">
                                                    <span class="title"><?=$row['game_type']?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="relative mrg-top-15">
                                                <span class="pdd-left-20"><?=$types[$row['type_id']]?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><b><?=$row['name']?></b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span>
                                                <?php
                                    if (isset($row['image'])) {
                                        ?>
                                                    <img class="thumb-img" src="<?=$row['image']?>" style="max-height:100px">
                                                <?php
                                    } else {
                                        echo '尚未上傳';
                                    }
                                    ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15 <?=($row['status'] != 1) ? 'text-danger' : ''?>">
                                               <?=($row['status'] == 1) ? '正常' : '停用'?>
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
                                                            <a href="edit.php?id=<?=$row['id']?>">編輯</a>
                                                        </li>
                                                        <li class="divider"></li>
                                                        <li>
                                                            <a href="#" class="game_toggle_status" id="<?=$row['id']?>" status="<?=($row['status'] != 1) ? 1 : 0?>"><?=($row['status'] != 1) ? '啟用' : '停用'?></a>
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
$( document ).ready(function() {
	//修改狀態
	$(document).on('click', '.game_toggle_status', function(e){
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		var id = $(this).attr('id');
		var status = $(this).attr('status');
		$.blockUI({
		    baseZ: 9000,
		    overlayCSS: { backgroundColor: '#444444' }
		})
    	$.post( "<?=BASE_URL?>ajax.php",{'action':'game_toggle_status','id':id,'status':status}, function( data ) {
    		$.unblockUI();
        	if(data.code == '1000'){
            	window.location.reload();
        	}else{
            	if(data.code == '2002'){
            		alert(data.data);
            	}else{
            		alert(data.message);
            	}
        	}            	
		},'json');
	});

});
</script>