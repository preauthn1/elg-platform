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
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/user/view', $data);
    if ($result['code'] == 1000) {
        $user = $result['data'];
    }
}
?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>後端管理 > 帳號/權限管理 > <?=(! isset($user)) ? $lang['add_user'] : $lang['edit_user']?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <div class="row">
                                    <div class="col-md-8 ml-auto mr-auto">
                                        <form id="data-form">
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?=! isset($user) ? '<span style="color:red">*</span>' : ''?>帳號</label>
                                                        <?php
                                                        if (isset($user)) {
                                                            ?>
                                                        	<input type="hidden" name="id" value="<?=$_GET['id']?>">
                                                        	<p><?=$user['username']?></p>
                                                        <?php
                                                        } else {
                                                            ?>
                                                        	<input type="text" placeholder="Account" name="username" class="form-control">
                                                        <?php
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label>暱稱</label>
                                                        <input type="text" placeholder="Enter nickname" class="form-control" name="nickname" value="<?=$user['nickname']?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?=! isset($user) ? '<span style="color:red">*</span>' : ''?>電子郵件</label>
                                                        <input type="email" placeholder="Enter email" class="form-control" name="email" value="<?=$user['email']?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?=! isset($user) ? '<span style="color:red">*</span>' : ''?>密碼</label>
                                                        <input type="password" placeholder="Password" class="form-control" name="password">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?=! isset($user) ? '<span style="color:red">*</span>' : ''?>確認密碼</label>
                                                        <input type="password" placeholder="Confirm Password" class="form-control" name="password_confirmation">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label>連絡電話</label>
                                                        <input type="text" placeholder="Mobile" class="form-control" name="mobile" value="<?=$user['mobile']?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label>狀態</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1" id="rad1" <?=($user['status'] == 1) ? 'checked' : ''?>>
                                                                <label for="rad1">啟用</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0" id="rad2" <?=($user['status'] != 1) ? 'checked' : ''?>>
                                                                <label for="rad2">停用</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                        <input type="hidden" name="action" value="save_user">
                                                        <input type="button" class="btn btn-default" value="回上頁" onclick="history.back();" />
                                                        <button type="submit" class="btn btn-primary" id="save">儲存</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
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
	$( "#save" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		$.blockUI({
		    baseZ: 9000,
		    overlayCSS: { backgroundColor: '#444444' }
		})
    	$.post( "<?=BASE_URL?>ajax.php",$( "#data-form" ).serialize(), function( data ) {
    		$.unblockUI();
        	if(data.code == 1000){
            	window.location = 'index.php';
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