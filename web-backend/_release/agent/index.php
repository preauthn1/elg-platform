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
$data = array();
// 查詢全部或第一層
if ($_GET['p'] != '-' && isset($_GET['p'])) {
    $data['parent'] = $_GET['p'];
}
// 查看下線
if (isset($_GET['id'])) {
    $data['parent'] = $_GET['id'];
}
$data['page'] = 1;
$data['per_page'] = 1000;
$result = curlPost('api/agent/list', $data);
if ($result['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>營運商管理 > 代理帳號管理</h4>
            </div>
            <a href="edit.php" class="btn btn-inverse btn-success"><?=$lang['add_agent']?></a>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-7">
                                    <h4 class="card-title">Search</h4>
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-2 control-label">營運商</label>
                                            <div class="col-md-10">
                                                <select id="selectize-dropdown">
<!--                                                     <option value="" disabled selected>選擇全部或是第一層營運商</option> -->
                                                    <option value="-" <?=($data['parent'] == '-') ? 'selected' : ''?>>全部</option>
                                                    <option value="1" <?=($data['parent'] == '1') ? 'selected' : ''?>>第一層</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label"></label>
                                            <div class="col-md-10">
                                                <button class="btn btn-primary search">Search</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>

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
                                            <th>連絡電話</th>
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
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20"><?=$row['username']?></span>
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
                                                                <a href="profile.php?id=<?=$row['id']?>">查看詳細資料</a>
                                                            </li>
                                                            <li>
                                                                <a href="#" data-toggle="modal" data-target="#domain_list" data-uid="<?=$row['id']?>" class="domain">網域設定</a>
                                                            </li>
                                                            <li>
                                                                <a href="index.php?id=<?=$row['id']?>" >查看下線</a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <a href="#" class="agent_toggle_status" id="<?=$row['id']?>" status="<?=($row['status'] != 1) ? 1 : 0?>"><?=($row['status'] != 1) ? '啟用' : '停用'?></a>
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
<div class="modal fade" id="domain_list">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="domain_content">
        </div>
    </div>
</div>

<div class="modal slide-in-right modal-right fade " id="domain_add">
    <div class="modal-dialog" role="document">
        <div class="modal-content" id="domain_add_content">
        </div>
    </div>
</div>
<script>
$( document ).ready(function() {
	//修改狀態
	$(document).on('click', '.agent_toggle_status', function(e){
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
    	$.post( "<?=BASE_URL?>ajax.php",{'action':'agent_toggle_status','id':id,'status':status}, function( data ) {
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

	//查詢
	$( ".search" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		var value = $('#selectize-dropdown').val();
		window.location = 'index.php?p='+value;
	});

	//開啟域名設定框
	$(document).on('click', '.domain', function(e){
		var id = $(this).attr('data-uid');
		$.post( "domain_list.php",{'id':id}, function( data ) {
			$( "#domain_content" ).html(data);
		});
	})
});
</script>