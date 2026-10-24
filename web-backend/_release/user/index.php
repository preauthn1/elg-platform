<?php
require_once '../_init.php';
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
$result = curlPost('api/user/list', $data);
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
                <h4>後端管理 > 帳號/權限管理</h4>
            </div>
            <a href="edit.php" class="btn btn-inverse btn-success"><?=$lang['add_user']?></a>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>帳號</th>
                                            <th>電子郵件</th>
                                            <th>手機號碼</th>
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
                                                    <img class="thumb-img" src="assets/images/avatars/thumb-4.jpg" alt="">
                                                    <div class="info">
                                                        <span class="title"><?=$row['username']?></span>
<!--                                                         <span class="sub-title">ID 861</span> -->
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?=$row['email']?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?=$row['mobile']?></span>
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
                                                            <li>
                                                                <a href="#" data-toggle="modal" data-target="#promission_modal" data-uid="<?=$row['id']?>" class="open_promission">權限設定</a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <a href="#" class="user_toggle_status" user_id="<?=$row['id']?>" status="<?=($row['status'] != 1) ? 1 : 0?>"><?=($row['status'] != 1) ? '啟用' : '停用'?></a>
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
<!-- Template START -->
<div class="modal fade" id="promission_modal">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title">功能清單</h4>
                                <p>可於下方清單管理啟用狀態及移除</p>
                                <div class="table-overflow" id="promission">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Template END -->
<?php
require_once '../_footer.php';
?>
<script>

$( document ).ready(function() {
	$('.open_promission').on('click', function (e) {
		var id = $(this).attr('data-uid');
		$.post( "promission.php",{'id':id}, function( data ) {
			$( "#promission" ).html(data);
		});
	})
	
	$(document).on('click', '.user_toggle_status', function(e){
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		var id = $(this).attr('user_id');
		var status = $(this).attr('status');

		$.blockUI({
		    baseZ: 9000,
		    overlayCSS: { backgroundColor: '#444444' }
		})
    	$.post( "<?=BASE_URL?>ajax.php",{'action':'user_toggle_status','id':id,'status':status}, function( data ) {
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