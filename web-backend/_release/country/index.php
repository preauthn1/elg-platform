<?php
require_once '../_init.php';
$result = curlPost('api/country/list');
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
                <h4>系統管理 > 國家 / 貨幣管理 > 國家管理</h4>
            </div>
            <a href="#" data-toggle="modal" data-target="#edit_country"  data-uid="0" class="btn btn-inverse btn-success edit">新增國家</a>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>名稱</th>
                                            <th>代碼</th>
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
                                                        <span class="title"><?=$row['name']?></span>
                                                        <span class="sub-title">ID <?=$row['id']?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20"><?=$row['code']?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <b class="text-dark font-size-16 <?=($row['status'] != 1) ? 'text-danger' : ''?>"><?=($row['status'] == 1) ? '正常' : '停用'?></b>
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
                                                                <a href="#" data-toggle="modal" data-target="#edit_country" data-uid="<?=$row['id']?>" class="edit">編輯</a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <a href="#" class="country_toggle_status" id="<?=$row['id']?>" status="<?=($row['status'] != 1) ? 1 : 0?>"><?=($row['status'] != 1) ? '啟用' : '停用'?></a>
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
<div class="modal fade" id="edit_country">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="country_content">
        </div>
    </div>
</div>
<script>
$( document ).ready(function() {
	//開啟編輯框
	$(document).on('click', '.edit', function(e){
		var id = $(this).attr('data-uid');
		$.post( "edit.php",{'id':id}, function( data ) {
			$( "#country_content" ).html(data);
		});
	})
	//修改狀態
	$(document).on('click', '.country_toggle_status', function(e){
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
    	$.post( "<?=BASE_URL?>ajax.php",{'action':'country_toggle_status','id':id,'status':status}, function( data ) {
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